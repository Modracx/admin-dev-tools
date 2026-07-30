<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Block\Adminhtml;

use Magento\Backend\Block\Template;

/**
 * Generic container for a lazily-loaded dropdown panel.
 *
 * Panels are rendered on demand by the controllers in Controller/Adminhtml, which set
 * the template and hand over the data — so nothing here costs anything on a normal
 * admin page render.
 */
class Panel extends Template
{
    /**
     * Human-readable file size.
     */
    public function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $power > 1 ? 1 : 0) . ' ' . $units[$power];
    }

    /**
     * A duration in seconds, in the largest unit that still says something useful.
     */
    public function formatDuration(int|float|null $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $seconds = (float) $seconds;

        if ($seconds < 1) {
            return '<1s';
        }
        if ($seconds < 60) {
            return round($seconds) . 's';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . str_pad((string) (int) round(fmod($seconds, 60)), 2, '0', STR_PAD_LEFT) . 's';
        }

        return floor($seconds / 3600) . 'h ' . str_pad((string) (int) floor(fmod($seconds, 3600) / 60), 2, '0', STR_PAD_LEFT) . 'm';
    }

    /**
     * How long ago a cron_schedule timestamp was.
     *
     * The column is UTC — the scheduler writes it from gmtTimestamp — so the string is
     * parsed as UTC explicitly rather than trusting PHP's default timezone, which on a
     * typical store is the locale's, not the database's.
     */
    public function formatAgo(?string $utcTimestamp): string
    {
        if ($utcTimestamp === null || $utcTimestamp === '' || str_starts_with($utcTimestamp, '0000')) {
            return '—';
        }

        $then = strtotime($utcTimestamp . ' UTC');
        if ($then === false) {
            return $utcTimestamp;
        }

        $delta = time() - $then;

        if ($delta < 0) {
            return (string) __('just now');
        }
        if ($delta < 60) {
            return (string) __('%1s ago', $delta);
        }
        if ($delta < 3600) {
            return (string) __('%1m ago', (int) floor($delta / 60));
        }
        if ($delta < 86400) {
            return (string) __('%1h ago', (int) floor($delta / 3600));
        }

        return (string) __('%1d ago', (int) floor($delta / 86400));
    }

    /**
     * Severity class for a log line, so errors stand out in a wall of text.
     */
    public function getLineLevel(string $line): string
    {
        if (preg_match('~\b(CRITICAL|ALERT|EMERGENCY|ERROR)\b~i', $line)) {
            return 'err';
        }
        if (preg_match('~\b(WARNING|NOTICE)\b~i', $line)) {
            return 'warn';
        }

        return '';
    }
}
