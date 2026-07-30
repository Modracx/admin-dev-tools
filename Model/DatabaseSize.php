<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Where the database went.
 *
 * Read-only on purpose. Emptying a table from a devbar button is a different class of
 * action from flushing a cache — it is not recoverable and the rows it removes are not
 * always as disposable as the table name suggests — so this panel names the offenders
 * and leaves the truncating to a human at a prompt.
 */
class DatabaseSize
{
    private const LIMIT = 25;

    /**
     * Tables that grow without bound and hold nothing a store needs to operate.
     * Matched as prefixes so per-entity changelogs are covered by one entry.
     */
    private const KNOWN_BLOAT = [
        'cron_schedule'            => 'Scheduler history. Safe to prune; Magento only reads recent rows.',
        'report_event'             => 'Reports "recently viewed/compared" tracking.',
        'reporting_'               => 'Advanced Reporting extract tables.',
        'magento_logging_event'    => 'Commerce admin action log.',
        'magento_logging_visitor'  => 'Commerce admin visitor log.',
        'customer_visitor'         => 'One row per visiting session.',
        'quote'                    => 'Abandoned carts. Pruned by the sales_clean_quotes cron.',
        'session'                  => 'Sessions, when they are stored in the database.',
        'search_query'             => 'Every search term ever typed.',
        'catalog_product_frontend_action' => 'Viewed/compared products per visitor.',
        'email_'                   => 'Newsletter/email queue tables.',
        'queue_message'            => 'MySQL message queue payloads.',
        'queue_message_status'     => 'MySQL message queue delivery state.',
    ];

    /** A changelog is any table Magento's mview machinery created for an index. */
    private const CHANGELOG_SUFFIX = '_cl';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @return array{
     *     database: string, total_bytes: int, table_count: int,
     *     tables: array<int, array{name: string, rows: int, data_bytes: int, index_bytes: int, total_bytes: int, share: float, note: string}>
     * }
     */
    public function getSummary(): array
    {
        $connection = $this->resource->getConnection();
        $database   = (string) $connection->fetchOne('SELECT DATABASE()');

        // information_schema figures are engine estimates for InnoDB, not exact counts.
        // For "what is eating the disk" that is exactly the right precision, and it costs
        // one query instead of a COUNT(*) per table.
        $totals = $connection->fetchRow(
            'SELECT COUNT(*) AS tables_count, SUM(data_length + index_length) AS total_bytes
             FROM information_schema.TABLES WHERE table_schema = ?',
            [$database]
        ) ?: [];

        $total = (int) ($totals['total_bytes'] ?? 0);

        $rows = $connection->fetchAll(
            'SELECT table_name, table_rows, data_length, index_length
             FROM information_schema.TABLES
             WHERE table_schema = ?
             ORDER BY (data_length + index_length) DESC
             LIMIT ' . self::LIMIT,
            [$database]
        );

        $tables = [];
        foreach ($rows as $row) {
            $name  = (string) ($row['table_name'] ?? $row['TABLE_NAME'] ?? '');
            $data  = (int) ($row['data_length'] ?? $row['DATA_LENGTH'] ?? 0);
            $index = (int) ($row['index_length'] ?? $row['INDEX_LENGTH'] ?? 0);
            $size  = $data + $index;

            $tables[] = [
                'name'        => $name,
                'rows'        => (int) ($row['table_rows'] ?? $row['TABLE_ROWS'] ?? 0),
                'data_bytes'  => $data,
                'index_bytes' => $index,
                'total_bytes' => $size,
                'share'       => $total > 0 ? round($size / $total * 100, 1) : 0.0,
                'note'        => $this->note($name),
            ];
        }

        return [
            'database'    => $database,
            'total_bytes' => $total,
            'table_count' => (int) ($totals['tables_count'] ?? 0),
            'tables'      => $tables,
        ];
    }

    /**
     * Tables that are large *and* known to be disposable — the ones worth acting on.
     *
     * @return array<int, array{name: string, rows: int, total_bytes: int, note: string}>
     */
    public function getBloat(): array
    {
        $bloat = [];
        foreach ($this->getSummary()['tables'] as $table) {
            if ($table['note'] !== '') {
                $bloat[] = [
                    'name'        => $table['name'],
                    'rows'        => $table['rows'],
                    'total_bytes' => $table['total_bytes'],
                    'note'        => $table['note'],
                ];
            }
        }

        return $bloat;
    }

    private function note(string $table): string
    {
        if (str_ends_with($table, self::CHANGELOG_SUFFIX)) {
            return (string) __(
                'Index changelog. Grows until the matching mview consumes it — a large one means cron is behind.'
            );
        }

        foreach (self::KNOWN_BLOAT as $needle => $note) {
            if ($table === $needle || str_starts_with($table, $needle)) {
                return (string) __($note);
            }
        }

        return '';
    }
}
