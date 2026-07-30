<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig\Reader as DeploymentReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Config\File\ConfigFilePool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Read-only inspection of configuration: what changed recently, and what a path
 * resolves to in each scope.
 *
 * Values are masked before they leave this class. Config legitimately holds API keys,
 * SMTP passwords and payment credentials, and this panel is one click away on every
 * admin page — a devbar is no place to render a live secret.
 */
class ConfigInspector
{
    private const TABLE = 'core_config_data';

    private const DEFAULT_LIMIT = 25;

    private const MAX_LIMIT = 100;

    /** Path fragments whose values are never shown. */
    private const SENSITIVE_PATTERN = '~(pass|secret|key|token|salt|private|credential|licen[cs]e|signature|cipher)~i';

    /** Magento's encrypted values look like "0:3:base64…". */
    private const ENCRYPTED_PATTERN = '~^\d+:\d+:~';

    private const MAX_VALUE_LENGTH = 200;

    private const PATH_PATTERN = '~^[a-z0-9_]+(/[a-z0-9_]+){1,5}$~i';

    /** Where a fixed value can come from, in the order Magento resolves them. */
    private const SOURCE_FILES = [
        ConfigFilePool::APP_ENV    => 'app/etc/env.php',
        ConfigFilePool::APP_CONFIG => 'app/etc/config.php',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $deployedByFile = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly SettingChecker $settingChecker,
        private readonly DeploymentReader $deploymentReader
    ) {
    }

    /**
     * Most recently edited rows of core_config_data.
     *
     * @return array<int, array{path: string, scope: string, scope_id: int, value: string, masked: bool, updated_at: ?string}>
     */
    public function getRecentChanges(int $limit = self::DEFAULT_LIMIT): array
    {
        $limit      = max(1, min($limit, self::MAX_LIMIT));
        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from(
                $this->resource->getTableName(self::TABLE),
                ['config_id', 'scope', 'scope_id', 'path', 'value', 'updated_at']
            )
            ->order(['updated_at DESC', 'config_id DESC'])
            ->limit($limit);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $path   = (string) $row['path'];
            $masked = $this->isSensitive($path, $row['value']);

            $rows[] = [
                'path'       => $path,
                'scope'      => (string) $row['scope'],
                'scope_id'   => (int) $row['scope_id'],
                'value'      => $this->presentValue($row['value'], $masked),
                'masked'     => $masked,
                'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
                // A row can be edited in the admin and still never be read: a deployed
                // value wins over the database, silently.
                'locked_by'  => $this->lockedBy($path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null),
            ];
        }

        return $rows;
    }

    /**
     * Resolve one config path in every scope, the way the application would read it —
     * so config.xml defaults and env.php overrides are included, not just DB rows.
     *
     * @return array<int, array{scope: string, value: string, masked: bool, set: bool}>
     * @throws LocalizedException
     */
    public function lookup(string $path): array
    {
        $path = trim($path, " \t\n\r\0\x0B/");

        if ($path === '' || !preg_match(self::PATH_PATTERN, $path)) {
            throw new LocalizedException(
                __('Enter a configuration path such as web/secure/base_url.')
            );
        }

        $masked  = $this->isSensitive($path, null);
        $results = [];

        $results[] = $this->resolve(
            $path,
            (string) __('Default'),
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            null,
            $masked,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            null
        );

        foreach ($this->storeManager->getWebsites() as $website) {
            $results[] = $this->resolve(
                $path,
                (string) __('Website: %1', $website->getName()),
                ScopeInterface::SCOPE_WEBSITE,
                (int) $website->getId(),
                $masked,
                ScopeInterface::SCOPE_WEBSITES,
                (string) $website->getCode()
            );
        }

        foreach ($this->storeManager->getStores() as $store) {
            $results[] = $this->resolve(
                $path,
                (string) __('Store view: %1', $store->getName()),
                ScopeInterface::SCOPE_STORE,
                (int) $store->getId(),
                $masked,
                ScopeInterface::SCOPE_STORES,
                (string) $store->getCode()
            );
        }

        return $results;
    }

    /**
     * @return array{scope: string, value: string, masked: bool, set: bool, locked_by: ?string}
     */
    private function resolve(
        string $path,
        string $label,
        string $scopeType,
        ?int $scopeId,
        bool $maskedByPath,
        string $lockScope,
        ?string $scopeCode
    ): array {
        $value  = $this->scopeConfig->getValue($path, $scopeType, $scopeId);
        $masked = $maskedByPath || $this->isSensitive($path, $value);

        return [
            'scope'     => $label,
            'value'     => $this->presentValue($value, $masked),
            'masked'    => $masked,
            'set'       => $value !== null && $value !== '',
            'locked_by' => $this->lockedBy($path, $lockScope, $scopeCode),
        ];
    }

    /**
     * Where a path is pinned outside the database, if it is.
     *
     * A value in app/etc/config.php, app/etc/env.php or a CONFIG__ environment variable
     * wins over core_config_data and greys the field out in the admin — so a save appears
     * to work and changes nothing. Magento's own SettingChecker decides *whether* a path
     * is fixed; the files are then read separately to say *which one* fixed it, because
     * "it's locked" and "it's locked in env.php on this server only" are different
     * problems with different fixes.
     */
    private function lockedBy(string $path, string $scope, ?string $scopeCode): ?string
    {
        if (!$this->settingChecker->isReadOnly($path, $scope, $scopeCode)) {
            return null;
        }

        if ($this->settingChecker->getPlaceholderValue($path, $scope, $scopeCode) !== null) {
            return (string) __('an environment variable');
        }

        $keys = ['system/' . $scope . ($scopeCode !== null ? '/' . $scopeCode : '') . '/' . $path];
        if ($scope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
            // SettingChecker falls back to the default scope, so a website-scope lookup can
            // be answered by a default-scope entry. Follow the same fallback here.
            $keys[] = 'system/' . ScopeConfigInterface::SCOPE_TYPE_DEFAULT . '/' . $path;
        }

        foreach ($this->deployedFiles() as $file => $data) {
            foreach ($keys as $key) {
                if ($this->dig($data, explode('/', $key)) !== null) {
                    return $file;
                }
            }
        }

        return (string) __('app/etc/config.php or app/etc/env.php');
    }

    /**
     * The two deployment files, read individually rather than through the merged
     * DeploymentConfig, which cannot say where a value came from.
     *
     * @return array<string, array<string, mixed>>
     */
    private function deployedFiles(): array
    {
        if ($this->deployedByFile !== null) {
            return $this->deployedByFile;
        }

        $this->deployedByFile = [];
        foreach (self::SOURCE_FILES as $pool => $label) {
            try {
                $this->deployedByFile[$label] = $this->deploymentReader->load($pool);
            } catch (\Exception $e) {
                $this->deployedByFile[$label] = [];
            }
        }

        return $this->deployedByFile;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string>   $segments
     */
    private function dig(array $data, array $segments): mixed
    {
        $node = $data;
        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    private function isSensitive(string $path, mixed $value): bool
    {
        if (preg_match(self::SENSITIVE_PATTERN, $path)) {
            return true;
        }

        return is_string($value) && $value !== '' && (bool) preg_match(self::ENCRYPTED_PATTERN, $value);
    }

    private function presentValue(mixed $value, bool $masked): string
    {
        if ($masked) {
            return '••••••••';
        }

        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            $value = json_encode($value);
        }

        $value = (string) $value;

        if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_VALUE_LENGTH) . '…';
        }

        return $value;
    }
}
