<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\ResourceInterface as ModuleResource;

/**
 * Which modules exist, which are enabled, and — the part worth having in a toolbar —
 * which ones have a schema version behind the version declared in their module.xml.
 *
 * That mismatch is the usual cause of "it works locally but not on staging": the code
 * was deployed but setup:upgrade never ran.
 */
class ModuleStatus
{
    public const STATE_OK        = 'ok';
    public const STATE_DISABLED  = 'disabled';
    public const STATE_PENDING   = 'pending';
    public const STATE_UNINSTALLED = 'uninstalled';

    public function __construct(
        private readonly ModuleListInterface $moduleList,
        private readonly FullModuleList $fullModuleList,
        private readonly ModuleResource $moduleResource
    ) {
    }

    /**
     * @return array{
     *     modules: array<int, array{name: string, vendor: string, enabled: bool, setup_version: ?string, db_version: ?string, state: string, note: string}>,
     *     summary: array{total: int, enabled: int, disabled: int, attention: int}
     * }
     */
    public function getModules(): array
    {
        $enabledNames = array_flip($this->moduleList->getNames());
        $modules      = [];
        $summary      = ['total' => 0, 'enabled' => 0, 'disabled' => 0, 'attention' => 0];

        foreach ($this->fullModuleList->getAll() as $name => $definition) {
            $enabled      = isset($enabledNames[$name]);
            $setupVersion = isset($definition['setup_version']) ? (string) $definition['setup_version'] : null;
            $dbVersion    = null;

            if ($enabled) {
                $version   = $this->moduleResource->getDbVersion($name);
                $dbVersion = $version === false ? null : (string) $version;
            }

            [$state, $note] = $this->evaluate($enabled, $setupVersion, $dbVersion);

            $modules[] = [
                'name'          => (string) $name,
                'vendor'        => explode('_', (string) $name)[0],
                'enabled'       => $enabled,
                'setup_version' => $setupVersion,
                'db_version'    => $dbVersion,
                'state'         => $state,
                'note'          => $note,
            ];

            $summary['total']++;
            $enabled ? $summary['enabled']++ : $summary['disabled']++;
            if ($state === self::STATE_PENDING || $state === self::STATE_UNINSTALLED) {
                $summary['attention']++;
            }
        }

        // Anything needing attention first, then disabled, then alphabetically.
        usort($modules, static function (array $a, array $b): int {
            $rank = static fn (array $m): int => match ($m['state']) {
                self::STATE_PENDING, self::STATE_UNINSTALLED => 0,
                self::STATE_DISABLED => 1,
                default => 2,
            };

            return [$rank($a), $a['name']] <=> [$rank($b), $b['name']];
        });

        return ['modules' => $modules, 'summary' => $summary];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function evaluate(bool $enabled, ?string $setupVersion, ?string $dbVersion): array
    {
        if (!$enabled) {
            return [self::STATE_DISABLED, (string) __('Disabled')];
        }

        // Declarative-schema modules declare no setup_version and track no db version;
        // absence of both is normal, not a problem.
        if ($setupVersion === null) {
            return [self::STATE_OK, ''];
        }

        if ($dbVersion === null) {
            return [self::STATE_UNINSTALLED, (string) __('Declares %1 but has no schema version — setup:upgrade has not run.', $setupVersion)];
        }

        if (version_compare($dbVersion, $setupVersion, '<')) {
            return [self::STATE_PENDING, (string) __('Schema at %1, code declares %2 — setup:upgrade is pending.', $dbVersion, $setupVersion)];
        }

        if (version_compare($dbVersion, $setupVersion, '>')) {
            return [self::STATE_PENDING, (string) __('Schema at %1 is ahead of code %2 — the code may have been rolled back.', $dbVersion, $setupVersion)];
        }

        return [self::STATE_OK, ''];
    }
}
