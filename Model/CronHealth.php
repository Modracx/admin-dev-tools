<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Cron\Model\ConfigInterface as CronConfig;
use Magento\Cron\Model\Schedule;
use Magento\Framework\App\ResourceConnection;

/**
 * Aggregate view of cron_schedule.
 *
 * Every query is bounded by scheduled_at so it can use the
 * CRON_SCHEDULE_SCHEDULED_AT_STATUS index instead of scanning a table that is
 * routinely millions of rows on a busy store.
 */
class CronHealth
{
    private const TABLE = 'cron_schedule';

    /** Window for the status counts and error list. */
    private const RECENT_HOURS = 24;

    /** How far back to look for the most recent successful run of a job. */
    private const SUCCESS_DAYS = 7;

    /** No successful run within this many minutes means cron is probably not running. */
    private const STALE_MINUTES = 60;

    private const ERROR_LIMIT = 10;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CronConfig $cronConfig
    ) {
    }

    /**
     * Row counts per status over the last 24 hours.
     *
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        $connection = $this->resource->getConnection();
        $select     = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['status', 'total' => new \Zend_Db_Expr('COUNT(*)')])
            ->where('scheduled_at >= ?', $this->since(self::RECENT_HOURS . ' HOUR'))
            ->group('status');

        $counts = [];
        foreach ($connection->fetchAll($select) as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Per cron group: how many jobs it declares, when it last succeeded, and how many
     * errors / missed / pending entries it has right now.
     *
     * @return array<int, array{group: string, jobs: int, last_success: ?string, error: int, missed: int, pending: int}>
     */
    public function getGroups(): array
    {
        $jobToGroup = [];
        foreach ($this->cronConfig->getJobs() as $group => $jobs) {
            foreach (array_keys($jobs) as $jobCode) {
                $jobToGroup[$jobCode] = (string) $group;
            }
        }

        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName(self::TABLE);

        // Last successful finish per job — separate query because the most recent
        // success is very often older than the 24h status window.
        $successSelect = $connection->select()
            ->from($table, ['job_code', 'last_success' => new \Zend_Db_Expr('MAX(finished_at)')])
            ->where('status = ?', Schedule::STATUS_SUCCESS)
            ->where('scheduled_at >= ?', $this->since(self::SUCCESS_DAYS . ' DAY'))
            ->group('job_code');

        $lastSuccess = [];
        foreach ($connection->fetchAll($successSelect) as $row) {
            $lastSuccess[(string) $row['job_code']] = (string) $row['last_success'];
        }

        $recentSelect = $connection->select()
            ->from($table, ['job_code', 'status', 'total' => new \Zend_Db_Expr('COUNT(*)')])
            ->where('scheduled_at >= ?', $this->since(self::RECENT_HOURS . ' HOUR'))
            ->group(['job_code', 'status']);

        $recent = [];
        foreach ($connection->fetchAll($recentSelect) as $row) {
            $recent[(string) $row['job_code']][(string) $row['status']] = (int) $row['total'];
        }

        $groups = [];
        foreach ($jobToGroup as $jobCode => $group) {
            if (!isset($groups[$group])) {
                $groups[$group] = [
                    'group'        => $group,
                    'jobs'         => 0,
                    'last_success' => null,
                    'error'        => 0,
                    'missed'       => 0,
                    'pending'      => 0,
                ];
            }

            $groups[$group]['jobs']++;

            if (isset($lastSuccess[$jobCode])
                && ($groups[$group]['last_success'] === null
                    || $lastSuccess[$jobCode] > $groups[$group]['last_success'])
            ) {
                $groups[$group]['last_success'] = $lastSuccess[$jobCode];
            }

            $groups[$group]['error']   += $recent[$jobCode][Schedule::STATUS_ERROR] ?? 0;
            $groups[$group]['missed']  += $recent[$jobCode][Schedule::STATUS_MISSED] ?? 0;
            $groups[$group]['pending'] += $recent[$jobCode][Schedule::STATUS_PENDING] ?? 0;
        }

        ksort($groups);

        return array_values($groups);
    }

    /**
     * Most recent failures, newest first.
     *
     * @return array<int, array{job_code: string, status: string, scheduled_at: ?string, executed_at: ?string, messages: ?string}>
     */
    public function getRecentErrors(): array
    {
        $connection = $this->resource->getConnection();
        $select     = $connection->select()
            ->from(
                $this->resource->getTableName(self::TABLE),
                ['job_code', 'status', 'scheduled_at', 'executed_at', 'messages']
            )
            ->where('status IN (?)', [Schedule::STATUS_ERROR, Schedule::STATUS_MISSED])
            ->where('scheduled_at >= ?', $this->since(self::RECENT_HOURS . ' HOUR'))
            ->order('schedule_id DESC')
            ->limit(self::ERROR_LIMIT);

        return $connection->fetchAll($select);
    }

    /**
     * Most recent successful finish across all jobs, plus how long ago that was.
     *
     * The age is computed in SQL so it is measured against the same clock that wrote
     * the row — PHP and MySQL do not necessarily agree on the timezone here.
     *
     * @return array{last: ?string, minutes: ?int}
     */
    public function getLastSuccess(): array
    {
        $connection = $this->resource->getConnection();
        $select     = $connection->select()
            ->from(
                $this->resource->getTableName(self::TABLE),
                [
                    'last'    => new \Zend_Db_Expr('MAX(finished_at)'),
                    'minutes' => new \Zend_Db_Expr('TIMESTAMPDIFF(MINUTE, MAX(finished_at), NOW())'),
                ]
            )
            ->where('status = ?', Schedule::STATUS_SUCCESS)
            ->where('scheduled_at >= ?', $this->since(self::SUCCESS_DAYS . ' DAY'));

        $row = $connection->fetchRow($select) ?: [];

        return [
            'last'    => !empty($row['last']) ? (string) $row['last'] : null,
            'minutes' => isset($row['minutes']) && $row['minutes'] !== null ? (int) $row['minutes'] : null,
        ];
    }

    /**
     * Single health verdict for the toolbar badge.
     *
     * @return array{level: string, label: string, detail: string}
     */
    public function getBadge(): array
    {
        $counts      = $this->getStatusCounts();
        $errors      = $counts[Schedule::STATUS_ERROR] ?? 0;
        $missed      = $counts[Schedule::STATUS_MISSED] ?? 0;
        $lastSuccess = $this->getLastSuccess();

        if ($lastSuccess['last'] === null) {
            return [
                'level'  => 'err',
                'label'  => (string) __('idle'),
                'detail' => (string) __('No cron job has succeeded in the last %1 days.', self::SUCCESS_DAYS),
            ];
        }

        if (($lastSuccess['minutes'] ?? 0) > self::STALE_MINUTES) {
            return [
                'level'  => 'err',
                'label'  => (string) __('stale'),
                'detail' => (string) __(
                    'No cron job has succeeded for %1 minutes (last: %2).',
                    $lastSuccess['minutes'],
                    $lastSuccess['last']
                ),
            ];
        }

        if ($errors > 0) {
            return [
                'level'  => 'err',
                'label'  => (string) $errors,
                'detail' => (string) __('%1 cron job(s) failed in the last %2 hours.', $errors, self::RECENT_HOURS),
            ];
        }

        if ($missed > 0) {
            return [
                'level'  => 'warn',
                'label'  => (string) $missed,
                'detail' => (string) __('%1 cron job(s) were missed in the last %2 hours.', $missed, self::RECENT_HOURS),
            ];
        }

        return [
            'level'  => 'ok',
            'label'  => (string) __('ok'),
            'detail' => (string) __('Last successful run: %1.', $lastSuccess['last']),
        ];
    }

    public function getRecentHours(): int
    {
        return self::RECENT_HOURS;
    }

    /**
     * A DB-side cutoff expression, so the comparison uses the database's clock —
     * the same one that wrote scheduled_at.
     */
    private function since(string $interval): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr(sprintf('DATE_SUB(NOW(), INTERVAL %s)', $interval));
    }
}
