<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Reads and maintains the activity log.
 *
 * Separate from ActivityLogger so the write path stays as small as possible — it runs
 * inside every backend save, and nothing here belongs in that hot path.
 */
class ActivityFeed
{
    private const DEFAULT_LIMIT = 60;
    private const MAX_LIMIT     = 200;

    /** Entries older than this are removed by the daily prune. */
    public const RETENTION_DAYS = 60;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ActivityLogger $activityLogger
    ) {
    }

    /**
     * False until setup:upgrade has created the table.
     */
    public function isAvailable(): bool
    {
        $connection = $this->resource->getConnection();

        return $connection->isTableExists($this->resource->getTableName(ActivityLogger::TABLE));
    }

    /**
     * Newest entries first, optionally filtered.
     *
     * @param array{source?: string, action?: string, q?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function getEntries(array $filters = [], int $limit = self::DEFAULT_LIMIT): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $limit      = max(1, min($limit, self::MAX_LIMIT));
        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from($this->resource->getTableName(ActivityLogger::TABLE))
            ->order('log_id DESC')
            ->limit($limit);

        if (!empty($filters['source'])) {
            $select->where('source = ?', (string) $filters['source']);
        }

        if (!empty($filters['action'])) {
            $select->where('action = ?', (string) $filters['action']);
        }

        if (!empty($filters['q'])) {
            // Zend substitutes the same bound value into every placeholder in the clause.
            $term = '%' . trim((string) $filters['q']) . '%';
            $select->where(
                '(entity_type LIKE ? OR entity_label LIKE ? OR endpoint LIKE ? OR actor_name LIKE ?)',
                $term
            );
        }

        $rows = $connection->fetchAll($select);

        foreach ($rows as &$row) {
            $row['changes'] = $this->decode($row['changes'] ?? null);
        }

        return $rows;
    }

    /**
     * Distinct sources present, for the filter control.
     *
     * @return string[]
     */
    public function getSources(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select     = $connection->select()
            ->distinct()
            ->from($this->resource->getTableName(ActivityLogger::TABLE), ['source'])
            ->order('source ASC');

        return array_map('strval', $connection->fetchCol($select));
    }

    public function getTotal(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $connection = $this->resource->getConnection();
        $select     = $connection->select()
            ->from($this->resource->getTableName(ActivityLogger::TABLE), ['total' => new \Zend_Db_Expr('COUNT(*)')]);

        return (int) $connection->fetchOne($select);
    }

    /**
     * Empty the log — and immediately record that it was emptied, by whom.
     *
     * A trail that can be wiped without leaving a mark is not much of a trail; the
     * clear itself is the one entry that survives.
     *
     * @return int rows removed
     */
    public function clear(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName(ActivityLogger::TABLE);

        $removed = (int) $connection->delete($table);

        $this->activityLogger->logAction(
            'delete',
            ActivityLogger::TABLE,
            (string) __('Activity log cleared'),
            ['entries_removed' => ['', (string) $removed]]
        );

        return $removed;
    }

    /**
     * Drop entries older than the retention window. Called from cron.
     *
     * @return int rows removed
     */
    public function prune(int $days = self::RETENTION_DAYS): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $connection = $this->resource->getConnection();

        return (int) $connection->delete(
            $this->resource->getTableName(ActivityLogger::TABLE),
            ['logged_at < ?' => new \Zend_Db_Expr(sprintf('DATE_SUB(NOW(), INTERVAL %d DAY)', max(1, $days)))]
        );
    }

    private function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }
}
