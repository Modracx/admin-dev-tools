<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;

/**
 * Reads the tail of Magento's log files.
 *
 * Only the fixed set below can ever be read — the request carries an id, never a path,
 * so there is no way to walk out of var/log. Reads take a bounded window from the end of
 * the file — scaled to the requested line count, never more than MAX_BYTES — so a
 * multi-gigabyte log cannot exhaust memory.
 */
class LogTail
{
    private const FILES = [
        'system'    => 'system.log',
        'exception' => 'exception.log',
        'debug'     => 'debug.log',
    ];

    /** Floor and ceiling for the read window taken from the end of a log. */
    private const MIN_BYTES = 262144;
    private const MAX_BYTES = 1048576;

    /** Rough budget per requested line, used to size the read window. */
    private const BYTES_PER_LINE = 2048;

    private const CHUNK = 8192;

    public const DEFAULT_LINES = 50;

    public const MAX_LINES = 1000;

    /** Choices offered in the panel. */
    public const LINE_CHOICES = [50, 100, 250, 500, 1000];

    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    /**
     * @return array<string, string> id => file name
     */
    public function getFiles(): array
    {
        return self::FILES;
    }

    public function has(string $id): bool
    {
        return isset(self::FILES[$id]);
    }

    /**
     * The log shown when no file has been chosen yet.
     */
    public function getDefaultId(): string
    {
        return (string) array_key_first(self::FILES);
    }

    /**
     * Last $lines lines of the given log.
     *
     * @return array{name: string, exists: bool, size: int, modified: ?int, lines: string[], truncated: bool}
     * @throws LocalizedException
     */
    public function tail(string $id, int $lines = self::DEFAULT_LINES): array
    {
        $name  = $this->getName($id);
        $lines = max(1, min($lines, self::MAX_LINES));
        $dir   = $this->filesystem->getDirectoryRead(DirectoryList::LOG);

        $result = [
            'name'      => $name,
            'exists'    => false,
            'size'      => 0,
            'modified'  => null,
            'lines'     => [],
            'truncated' => false,
        ];

        if (!$dir->isExist($name) || !$dir->isFile($name)) {
            return $result;
        }

        $stat = $dir->stat($name);
        $size = (int) ($stat['size'] ?? 0);

        $result['exists']   = true;
        $result['size']     = $size;
        $result['modified'] = isset($stat['mtime']) ? (int) $stat['mtime'] : null;

        if ($size === 0) {
            return $result;
        }

        $offset = max(0, $size - $this->readWindow($lines));
        $file   = $dir->openFile($name);

        try {
            if ($offset > 0) {
                $file->seek($offset);
            }
            $content = '';
            while (!$file->eof()) {
                $chunk = $file->read(self::CHUNK);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                $content .= $chunk;
            }
        } finally {
            $file->close();
        }

        $split = preg_split("/\r\n|\n|\r/", rtrim($content, "\r\n")) ?: [];

        // A mid-line start would render as a corrupt first entry.
        if ($offset > 0 && count($split) > 1) {
            array_shift($split);
            $result['truncated'] = true;
        }

        if (count($split) > $lines) {
            $split = array_slice($split, -$lines);
            $result['truncated'] = true;
        }

        $result['lines'] = $split;

        return $result;
    }

    /**
     * How far back from the end of the file to read.
     *
     * Scaled to the requested line count so asking for 1000 lines does not silently
     * return 50, while still bounded so a huge log can never exhaust memory.
     */
    private function readWindow(int $lines): int
    {
        return max(self::MIN_BYTES, min(self::MAX_BYTES, $lines * self::BYTES_PER_LINE));
    }

    /**
     * Truncate the log in place, keeping the file (and its permissions) around.
     *
     * @throws LocalizedException
     */
    public function clear(string $id): void
    {
        $name = $this->getName($id);
        $dir  = $this->filesystem->getDirectoryWrite(DirectoryList::LOG);

        if ($dir->isExist($name)) {
            $dir->writeFile($name, '');
        }
    }

    /**
     * @throws LocalizedException
     */
    private function getName(string $id): string
    {
        if (!isset(self::FILES[$id])) {
            throw new LocalizedException(__('Unknown log file.'));
        }

        return self::FILES[$id];
    }
}
