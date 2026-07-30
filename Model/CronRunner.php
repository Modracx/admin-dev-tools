<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Cron\Model\ConfigInterface as CronConfig;
use Magento\Cron\Model\Schedule;
use Magento\Cron\Model\ScheduleFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Run one cron job now, in the current request.
 *
 * This is deliberately a single job and not a whole group: a group run is what the
 * scheduler is for, and pushing one through a web request would sit on an admin
 * connection for minutes and then die halfway through a queue consumer.
 *
 * The run is written to cron_schedule exactly as the scheduler would write it —
 * running, then success or error, with timings. A manual run that left no trace would
 * make the very panel it was launched from lie about what has happened on this store.
 */
class CronRunner
{
    private const TABLE = 'cron_schedule';

    /** Ceiling for a manual run, in seconds. Long enough to be useful, short of a gateway timeout. */
    private const TIME_LIMIT = 300;

    public function __construct(
        private readonly CronConfig $cronConfig,
        private readonly ScheduleFactory $scheduleFactory,
        private readonly ObjectManagerInterface $objectManager,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @return array{job_code: string, group: string, seconds: int, output: string}
     * @throws LocalizedException
     */
    public function run(string $jobCode): array
    {
        [$group, $job] = $this->findJob($jobCode);

        $instance = (string) ($job['instance'] ?? '');
        $method   = (string) ($job['method'] ?? '');

        if ($instance === '' || $method === '') {
            throw new LocalizedException(
                __('Job "%1" declares no instance/method to call.', $jobCode)
            );
        }

        if ($this->isRunning($jobCode)) {
            throw new LocalizedException(
                __('Job "%1" is already marked as running. Wait for it to finish, or clear the stale row.', $jobCode)
            );
        }

        // Core resolves the class at call time for the same reason — the class name comes
        // from merged crontab.xml and cannot be a constructor dependency.
        $model = $this->objectManager->create($instance);

        if (!method_exists($model, $method)) {
            throw new LocalizedException(
                __('%1::%2() does not exist — check the crontab.xml entry for "%3".', $instance, $method, $jobCode)
            );
        }

        $schedule = $this->open($jobCode);
        $started  = microtime(true);

        if (function_exists('set_time_limit')) {
            @set_time_limit(self::TIME_LIMIT);
        }

        try {
            $output = $model->$method($schedule);
        } catch (\Throwable $e) {
            $this->close($schedule, Schedule::STATUS_ERROR, $this->describe($e));

            // A fatal from a job arrives as \Error, which LocalizedException will not accept
            // as a cause — pass it only when it is really an \Exception.
            throw new LocalizedException(
                __('%1 failed after %2s: %3', $jobCode, $this->elapsed($started), $e->getMessage()),
                $e instanceof \Exception ? $e : null
            );
        }

        $this->close($schedule, Schedule::STATUS_SUCCESS, (string) __('Run manually from Modracx dev tools.'));

        return [
            'job_code' => $jobCode,
            'group'    => $group,
            'seconds'  => $this->elapsed($started),
            'output'   => is_scalar($output) ? (string) $output : '',
        ];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     * @throws LocalizedException
     */
    private function findJob(string $jobCode): array
    {
        foreach ($this->cronConfig->getJobs() as $group => $jobs) {
            if (isset($jobs[$jobCode])) {
                return [(string) $group, (array) $jobs[$jobCode]];
            }
        }

        throw new LocalizedException(
            __('No cron job named "%1" is declared by any enabled module.', $jobCode)
        );
    }

    /**
     * Is the scheduler — or another admin — already inside this job?
     *
     * Bounded by scheduled_at like every other query here, so it uses the index rather
     * than scanning; a running row older than the window is a crash, not a run.
     */
    private function isRunning(string $jobCode): bool
    {
        $connection = $this->resource->getConnection();
        $select     = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['schedule_id'])
            ->where('job_code = ?', $jobCode)
            ->where('status = ?', Schedule::STATUS_RUNNING)
            ->where('scheduled_at >= ?', new \Zend_Db_Expr('DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)'))
            ->limit(1);

        return (bool) $connection->fetchOne($select);
    }

    /**
     * Timestamps come from gmtDate() because that is what the scheduler writes; a manual
     * run stamped in local time would sit hours away from every scheduled row beside it.
     */
    private function open(string $jobCode): Schedule
    {
        $now = $this->dateTime->gmtDate();

        $schedule = $this->scheduleFactory->create();
        $schedule->setJobCode($jobCode)
            ->setStatus(Schedule::STATUS_RUNNING)
            ->setCreatedAt($now)
            ->setScheduledAt($now)
            ->setExecutedAt($now)
            ->save();

        return $schedule;
    }

    private function close(Schedule $schedule, string $status, string $messages): void
    {
        try {
            $schedule->setStatus($status)
                ->setMessages($messages)
                ->setFinishedAt($this->dateTime->gmtDate())
                ->save();
        } catch (\Throwable $e) {
            // Never let bookkeeping mask the job's own outcome.
            return;
        }
    }

    /**
     * Enough of the failure to act on, without pasting a whole stack trace into a
     * table row that the panel then has to render.
     */
    private function describe(\Throwable $e): string
    {
        return sprintf(
            "%s: %s\n%s:%d",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
    }

    private function elapsed(float $started): int
    {
        return (int) round(microtime(true) - $started);
    }
}
