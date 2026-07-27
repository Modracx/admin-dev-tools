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
