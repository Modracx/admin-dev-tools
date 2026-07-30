<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Cron\Model\ConfigInterface as CronConfig;
use Magento\Cron\Model\Schedule;
use Magento\Framework\App\Config\ScopeConfigInterface;
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

    /** How many rows the run history shows. */
    private const RUN_LIMIT = 40;

    /** Statuses that describe a run that is over, one way or another. */
    private const TERMINAL = [Schedule::STATUS_SUCCESS, Schedule::STATUS_ERROR, Schedule::STATUS_MISSED];

    /** @var array<string, array<string, int>>|null */
    private ?array $recentByJob = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CronConfig $cronConfig,
        private readonly ScopeConfigInterface $scopeConfig
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

        $recent = $this->getRecentCountsByJob();

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
     * Every declared job, with what the schedule says and what the table shows it did.
     *
     * Built from the merged crontab.xml rather than from cron_schedule, so a job that has
     * never been scheduled — the interesting case when cron is not running — still appears
     * instead of silently missing from the list.
     *
     * @return array<int, array{
     *     job_code: string, group: string, schedule: string, instance: string,
     *     last_status: ?string, last_run: ?string, last_seconds: ?int, last_message: ?string,
     *     avg_seconds: ?float, max_seconds: ?int, runs: int,
     *     success: int, error: int, missed: int, pending: int
     * }>
     */
    public function getJobs(): array
    {
        $recent    = $this->getRecentCountsByJob();
        $lastRuns  = $this->getLastRunByJob();
        $durations = $this->getDurationsByJob();

        $jobs = [];
        foreach ($this->cronConfig->getJobs() as $group => $groupJobs) {
            foreach ($groupJobs as $jobCode => $job) {
                $code    = (string) $jobCode;
                $last    = $lastRuns[$code] ?? null;
                $timing  = $durations[$code] ?? null;

                $jobs[] = [
                    'job_code'     => $code,
                    'group'        => (string) $group,
                    'schedule'     => $this->resolveSchedule($job),
                    'instance'     => (string) ($job['instance'] ?? ''),
                    'last_status'  => $last['status'] ?? null,
                    'last_run'     => $last['ran_at'] ?? null,
                    'last_seconds' => isset($last['seconds']) ? (int) $last['seconds'] : null,
                    'last_message' => $last['messages'] ?? null,
                    'avg_seconds'  => isset($timing['avg_seconds']) ? (float) $timing['avg_seconds'] : null,
                    'max_seconds'  => isset($timing['max_seconds']) ? (int) $timing['max_seconds'] : null,
                    'runs'         => (int) ($timing['runs'] ?? 0),
                    'success'      => $recent[$code][Schedule::STATUS_SUCCESS] ?? 0,
                    'error'        => $recent[$code][Schedule::STATUS_ERROR] ?? 0,
                    'missed'       => $recent[$code][Schedule::STATUS_MISSED] ?? 0,
                    'pending'      => $recent[$code][Schedule::STATUS_PENDING] ?? 0,
                ];
            }
        }

        // Anything that failed floats to the top; the rest stay alphabetical so the list
        // is stable between refreshes and can be scanned like an index.
        usort($jobs, static function (array $a, array $b): int {
            $rank = static fn (array $j): int => match (true) {
                $j['error'] > 0                                => 0,
                $j['missed'] > 0                               => 1,
                $j['last_status'] === Schedule::STATUS_ERROR   => 2,
                default                                        => 3,
            };

            return [$rank($a), $a['job_code']] <=> [$rank($b), $b['job_code']];
        });

        return $jobs;
    }

    /**
     * Run history — successes and failures together, newest first.
     *
     * The mixed list is the point: a failure means something different when the ten runs
     * around it succeeded than when nothing has finished all day.
     *
     * @param  string $filter One of all|success|error|missed
     * @return array<int, array{job_code: string, status: string, scheduled_at: ?string, executed_at: ?string, finished_at: ?string, seconds: ?int, messages: ?string}>
     */
    public function getRecentRuns(string $filter = 'all'): array
    {
        $statuses = match ($filter) {
            Schedule::STATUS_SUCCESS => [Schedule::STATUS_SUCCESS],
            Schedule::STATUS_ERROR   => [Schedule::STATUS_ERROR],
            Schedule::STATUS_MISSED  => [Schedule::STATUS_MISSED],
            'failed'                 => [Schedule::STATUS_ERROR, Schedule::STATUS_MISSED],
            default                  => self::TERMINAL,
        };

        $connection = $this->resource->getConnection();
        $select     = $connection->select()
            ->from(
                $this->resource->getTableName(self::TABLE),
                [
                    'job_code',
                    'status',
                    'scheduled_at',
                    'executed_at',
                    'finished_at',
                    'messages',
                    'seconds' => new \Zend_Db_Expr('TIMESTAMPDIFF(SECOND, executed_at, finished_at)'),
                ]
            )
            ->where('status IN (?)', $statuses)
            ->where('scheduled_at >= ?', $this->since(self::SUCCESS_DAYS . ' DAY'))
            ->order('schedule_id DESC')
            ->limit(self::RUN_LIMIT);

        return $connection->fetchAll($select);
    }

    /**
     * The last finished run of every job within the success window.
     *
     * @return array<string, array{status: string, ran_at: ?string, seconds: ?int, messages: ?string}>
     */
    private function getLastRunByJob(): array
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName(self::TABLE);

        // Group-wise maximum: pick the newest finished row per job, then read that row.
        // schedule_id is the primary key and monotonic, so it orders runs without
        // depending on any of the nullable timestamp columns.
        $latest = $connection->select()
            ->from($table, ['last_id' => new \Zend_Db_Expr('MAX(schedule_id)')])
            ->where('status IN (?)', self::TERMINAL)
            ->where('scheduled_at >= ?', $this->since(self::SUCCESS_DAYS . ' DAY'))
            ->group('job_code');

        $select = $connection->select()
            ->from(
                $table,
                [
                    'job_code',
                    'status',
                    'messages',
                    'ran_at'  => new \Zend_Db_Expr('COALESCE(finished_at, executed_at, scheduled_at)'),
                    'seconds' => new \Zend_Db_Expr('TIMESTAMPDIFF(SECOND, executed_at, finished_at)'),
                ]
            )
            ->where('schedule_id IN (?)', new \Zend_Db_Expr($latest->assemble()));

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[(string) $row['job_code']] = $row;
        }

        return $rows;
    }

    /**
     * How long each job takes when it works: run count, mean and worst.
     *
     * Only successful runs are measured — a job that died after two seconds says nothing
     * about how long it takes to do its work.
     *
     * @return array<string, array{runs: int, avg_seconds: ?float, max_seconds: ?int}>
     */
    private function getDurationsByJob(): array
    {
        $connection = $this->resource->getConnection();
        $select     = $connection->select()
            ->from(
                $this->resource->getTableName(self::TABLE),
                [
                    'job_code',
                    'runs'        => new \Zend_Db_Expr('COUNT(*)'),
                    'avg_seconds' => new \Zend_Db_Expr('AVG(TIMESTAMPDIFF(SECOND, executed_at, finished_at))'),
                    'max_seconds' => new \Zend_Db_Expr('MAX(TIMESTAMPDIFF(SECOND, executed_at, finished_at))'),
                ]
            )
            ->where('status = ?', Schedule::STATUS_SUCCESS)
            ->where('executed_at IS NOT NULL')
            ->where('finished_at IS NOT NULL')
            ->where('scheduled_at >= ?', $this->since(self::SUCCESS_DAYS . ' DAY'))
            ->group('job_code');

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[(string) $row['job_code']] = $row;
        }

        return $rows;
    }

    /**
     * Per job, per status row counts over the recent window. Memoised: the groups list and
     * the jobs list both want it, and a panel render should not pay for it twice.
     *
     * @return array<string, array<string, int>>
     */
    private function getRecentCountsByJob(): array
    {
        if ($this->recentByJob !== null) {
            return $this->recentByJob;
        }

        $connection = $this->resource->getConnection();
        $select     = $connection->select()
            ->from(
                $this->resource->getTableName(self::TABLE),
                ['job_code', 'status', 'total' => new \Zend_Db_Expr('COUNT(*)')]
            )
            ->where('scheduled_at >= ?', $this->since(self::RECENT_HOURS . ' HOUR'))
            ->group(['job_code', 'status']);

        $this->recentByJob = [];
        foreach ($connection->fetchAll($select) as $row) {
            $this->recentByJob[(string) $row['job_code']][(string) $row['status']] = (int) $row['total'];
        }

        return $this->recentByJob;
    }

    /**
     * A job declares either a literal cron expression or a config path holding one.
     *
     * @param array<string, mixed> $job
     */
    private function resolveSchedule(array $job): string
    {
        if (!empty($job['schedule'])) {
            return (string) $job['schedule'];
        }

        if (!empty($job['config_path'])) {
            $value = (string) $this->scopeConfig->getValue((string) $job['config_path']);

            return $value !== ''
                ? $value
                : (string) __('unset (%1)', $job['config_path']);
        }

        return (string) __('no schedule');
    }

    /**
     * Most recent successful finish across all jobs, plus how long ago that was.
     *
     * The age is computed in SQL, against UTC_TIMESTAMP() rather than NOW(). Magento's
     * adapter pins the session to '+00:00' so today the two are the same thing, but cron
     * writes these columns in UTC on purpose (ProcessCronQueueObserver stamps them from
     * gmtTimestamp) — naming UTC explicitly keeps the comparison correct even on a
     * connection whose timezone is not pinned, and says which clock is meant.
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
                    'minutes' => new \Zend_Db_Expr('TIMESTAMPDIFF(MINUTE, MAX(finished_at), UTC_TIMESTAMP())'),
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

    public function getSuccessDays(): int
    {
        return self::SUCCESS_DAYS;
    }

    /**
     * A DB-side cutoff expression in UTC, matching the clock cron writes these columns in.
     * See getLastSuccess() for why it names UTC_TIMESTAMP() rather than NOW().
     */
    private function since(string $interval): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr(sprintf('DATE_SUB(UTC_TIMESTAMP(), INTERVAL %s)', $interval));
    }
}
