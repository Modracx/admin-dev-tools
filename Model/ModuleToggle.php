<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Framework\App\Cache\Manager as CacheManager;
use Magento\Framework\App\DeploymentConfig\Writer;
use Magento\Framework\App\State;
use Magento\Framework\Code\GeneratedFiles;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\Status;

/**
 * Enable or disable a module, mirroring bin/magento module:enable|disable.
 *
 * This writes app/etc/config.php and is the most consequential thing in this extension:
 * a wrong move here can take the whole site down, admin included. The guards below are
 * therefore not optional politeness — they are the reason this is safe to expose:
 *
 *  - Production mode is refused outright. There, generated/ and the DI compilation are
 *    built ahead of time; changing the module list from a web request leaves compiled
 *    code referencing a module that no longer loads, and the recovery is CLI-only.
 *  - Magento's own dependency and conflict checks run first, unchanged. There is no
 *    --force equivalent here, so a module something else depends on cannot be disabled.
 *  - A short list of modules that would lock you out of the admin is protected outright,
 *    including this one.
 *  - config.php is checked for writability before anything is attempted, so a failure
 *    surfaces as a message rather than a half-applied change.
 *
 * Enabling still needs `setup:upgrade` afterwards to install the module's schema and
 * data; that is stated in the response rather than attempted from here, because a
 * long-running upgrade does not belong in an AJAX request.
 */
class ModuleToggle
{
    /**
     * Disabling any of these breaks the admin, which is the only way back in.
     * Magento's dependency check catches most of it; this closes the rest.
     */
    private const PROTECTED_MODULES = [
        'Magento_Backend',
        'Magento_Store',
        'Magento_User',
        'Magento_Authorization',
        'Magento_Config',
        'Magento_Ui',
        'Magento_Theme',
        'Magento_Directory',
        'Magento_Eav',
        'Magento_Security',
        'Magento_AdminAnalytics',
        'Modracx_AdminDevTools',
    ];

    public function __construct(
        private readonly Status $status,
        private readonly FullModuleList $fullModuleList,
        private readonly State $appState,
        private readonly Writer $deploymentWriter,
        private readonly GeneratedFiles $generatedFiles,
        private readonly CacheManager $cacheManager
    ) {
    }

    /**
     * Whether toggling is possible at all in this environment.
     */
    public function isAvailable(): bool
    {
        return $this->appState->getMode() !== State::MODE_PRODUCTION;
    }

    public function getUnavailableReason(): string
    {
        if (!$this->isAvailable()) {
            return (string) __(
                'The store is in production mode. Changing the module list requires '
                . 'setup:di:compile afterwards, so use bin/magento module:enable|disable from the CLI.'
            );
        }

        return '';
    }

    public function isProtected(string $moduleName): bool
    {
        return in_array($moduleName, self::PROTECTED_MODULES, true);
    }

    /**
     * @return string the message to show on success
     * @throws LocalizedException
     */
    public function toggle(string $moduleName, bool $enable): string
    {
        $this->assertCanToggle($moduleName, $enable);

        $changed = $this->status->getModulesToChange($enable, [$moduleName]);
        if (empty($changed)) {
            throw new LocalizedException(
                __('%1 is already %2.', $moduleName, $enable ? __('enabled') : __('disabled'))
            );
        }

        // Magento's own dependency/conflict rules, applied exactly as the CLI applies them.
        $constraints = $this->status->checkConstraints($enable, $changed);
        if (!empty($constraints)) {
            throw new LocalizedException(__(implode(' ', $constraints)));
        }

        $this->status->setIsEnabled($enable, $changed);

        // generated/ still holds interceptors and factories for the old module list.
        $this->generatedFiles->requestRegeneration();
        $this->cacheManager->clean($this->cacheManager->getAvailableTypes());

        if ($enable) {
            return (string) __(
                '%1 enabled. Run bin/magento setup:upgrade to install its schema and data.',
                $moduleName
            );
        }

        return (string) __('%1 disabled. Reload the page for the change to take full effect.', $moduleName);
    }

    /**
     * @throws LocalizedException
     */
    private function assertCanToggle(string $moduleName, bool $enable): void
    {
        if (!$this->isAvailable()) {
            throw new LocalizedException(__($this->getUnavailableReason()));
        }

        if (!preg_match('~^[A-Za-z0-9]+_[A-Za-z0-9]+$~', $moduleName)) {
            throw new LocalizedException(__('Invalid module name.'));
        }

        if (!$this->fullModuleList->has($moduleName)) {
            throw new LocalizedException(__('Module %1 is not installed.', $moduleName));
        }

        if (!$enable && $this->isProtected($moduleName)) {
            throw new LocalizedException(
                __('%1 cannot be disabled here — doing so would lock you out of the admin.', $moduleName)
            );
        }

        try {
            $this->deploymentWriter->checkIfWritable();
        } catch (\Exception $e) {
            throw new LocalizedException(__('app/etc/config.php is not writable: %1', $e->getMessage()));
        }
    }
}
