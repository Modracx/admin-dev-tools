<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
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

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
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
            $masked
        );

        foreach ($this->storeManager->getWebsites() as $website) {
            $results[] = $this->resolve(
                $path,
                (string) __('Website: %1', $website->getName()),
                ScopeInterface::SCOPE_WEBSITE,
                (int) $website->getId(),
                $masked
            );
        }

        foreach ($this->storeManager->getStores() as $store) {
            $results[] = $this->resolve(
                $path,
                (string) __('Store view: %1', $store->getName()),
                ScopeInterface::SCOPE_STORE,
                (int) $store->getId(),
                $masked
            );
        }

        return $results;
    }

    /**
     * @return array{scope: string, value: string, masked: bool, set: bool}
     */
    private function resolve(string $path, string $label, string $scopeType, ?int $scopeId, bool $maskedByPath): array
    {
        $value  = $this->scopeConfig->getValue($path, $scopeType, $scopeId);
        $masked = $maskedByPath || $this->isSensitive($path, $value);

        return [
            'scope'  => $label,
            'value'  => $this->presentValue($value, $masked),
            'masked' => $masked,
            'set'    => $value !== null && $value !== '',
        ];
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
