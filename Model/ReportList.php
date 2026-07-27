<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * The exception reports Magento writes to var/report — the files behind the storefront's
 * "an error has occurred, your report id is …" page.
 *
 * A report is a serialized array: [0 => message, 1 => trace, 'url' => …,
 * 'script_name' => …, 'report_id' => …]. They may sit in nested directories depending on
 * report/dir_nesting_level, so the directory is walked rather than listed flat.
 *
 * A requested id is resolved by matching it against that listing, never by building a
 * path from the parameter — so no request can address a file outside var/report.
 */
class ReportList
{
    private const DIR = 'report';

    /** Newest reports shown in the list. */
    private const LIST_LIMIT = 50;

    /** Upper bound on files examined, so a directory with 100k reports stays responsive. */
    private const SCAN_LIMIT = 2000;

    /** A report body larger than this is almost certainly not worth rendering in a panel. */
    private const MAX_BYTES = 262144;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Json $json
    ) {
    }

    public function isAvailable(): bool
    {
        $dir = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);

        return $dir->isExist(self::DIR) && $dir->isDirectory(self::DIR);
    }

    /**
     * Newest reports first.
     *
     * @return array{reports: array<int, array{id: string, path: string, modified: ?int, size: int, message: string}>, total: int, truncated: bool}
     */
    public function getReports(): array
    {
        $result = ['reports' => [], 'total' => 0, 'truncated' => false];

        if (!$this->isAvailable()) {
            return $result;
        }

        $dir   = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $paths = $this->walk($dir, self::DIR);
        $files = [];

        if (count($paths) >= self::SCAN_LIMIT) {
            $result['truncated'] = true;
        }

        foreach ($paths as $path) {
            $stat    = $dir->stat($path);
            $files[] = [
                'id'       => basename($path),
                'path'     => $path,
                'modified' => isset($stat['mtime']) ? (int) $stat['mtime'] : null,
                'size'     => (int) ($stat['size'] ?? 0),
            ];
        }

        $result['total'] = count($files);

        usort($files, static fn (array $a, array $b): int => ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0));

        if (count($files) > self::LIST_LIMIT) {
            $files = array_slice($files, 0, self::LIST_LIMIT);
            $result['truncated'] = true;
        }

        foreach ($files as $file) {
            $report            = $this->read($file['path']);
            $file['message']   = $this->firstLine((string) ($report['message'] ?? ''));
            $result['reports'][] = $file;
        }

        return $result;
    }

    /**
     * One report in full.
     *
     * @return array{id: string, message: string, trace: string, url: string, script_name: string, modified: ?int}
     * @throws LocalizedException
     */
    public function getReport(string $id): array
    {
        $path = $this->resolve($id);

        if ($path === null) {
            throw new LocalizedException(__('Report not found.'));
        }

        $dir    = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $stat   = $dir->stat($path);
        $report = $this->read($path);

        return [
            'id'          => $id,
            'message'     => (string) ($report['message'] ?? ''),
            'trace'       => (string) ($report['trace'] ?? ''),
            'url'         => (string) ($report['url'] ?? ''),
            'script_name' => (string) ($report['script_name'] ?? ''),
            'modified'    => isset($stat['mtime']) ? (int) $stat['mtime'] : null,
        ];
    }

    /**
     * Match an id against the actual directory listing. Returning null for anything not
     * found there is what makes a crafted id harmless.
     */
    private function resolve(string $id): ?string
    {
        if ($id === '' || !preg_match('~^[A-Za-z0-9_-]{1,128}$~', $id) || !$this->isAvailable()) {
            return null;
        }

        $dir = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);

        foreach ($this->walk($dir, self::DIR) as $path) {
            if (basename($path) === $id) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Collect report file paths, depth- and count-bounded.
     *
     * Uses only ReadInterface methods; readRecursively() lives on the concrete class.
     * Reports nest at most a couple of levels (report/dir_nesting_level), so a depth cap
     * of 4 covers every real configuration while ruling out a runaway walk.
     *
     * @return string[]
     */
    private function walk(\Magento\Framework\Filesystem\Directory\ReadInterface $dir, string $path, int $depth = 0): array
    {
        if ($depth > 4) {
            return [];
        }

        $files = [];

        foreach ($dir->read($path) as $entry) {
            if (count($files) >= self::SCAN_LIMIT) {
                break;
            }

            if ($dir->isDirectory($entry)) {
                foreach ($this->walk($dir, $entry, $depth + 1) as $nested) {
                    $files[] = $nested;
                    if (count($files) >= self::SCAN_LIMIT) {
                        break;
                    }
                }
            } elseif ($dir->isFile($entry)) {
                $files[] = $entry;
            }
        }

        return $files;
    }

    /**
     * @return array{message: string, trace: string, url: string, script_name: string}
     */
    private function read(string $path): array
    {
        $empty = ['message' => '', 'trace' => '', 'url' => '', 'script_name' => ''];
        $dir   = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);

        try {
            $stat = $dir->stat($path);
            if ((int) ($stat['size'] ?? 0) > self::MAX_BYTES) {
                return ['message' => (string) __('Report is too large to display.')] + $empty;
            }
            $raw = $dir->readFile($path);
        } catch (\Exception $e) {
            return ['message' => $e->getMessage()] + $empty;
        }

        $data = $this->decode(trim((string) $raw));

        if (!is_array($data)) {
            // Not a structured report — show whatever is in the file.
            return ['message' => (string) $raw] + $empty;
        }

        return [
            'message'     => (string) ($data[0] ?? ''),
            'trace'       => (string) ($data[1] ?? ''),
            'url'         => (string) ($data['url'] ?? ''),
            'script_name' => (string) ($data['script_name'] ?? ''),
        ];
    }

    /**
     * Current Magento writes JSON; reports left over from older versions are PHP-serialized.
     */
    private function decode(string $raw): mixed
    {
        if ($raw === '') {
            return null;
        }

        try {
            return $this->json->unserialize($raw);
        } catch (\Exception $e) {
            // Fall through to the legacy format.
        }

        // allowed_classes: false — a report is plain data, and this file is attacker-adjacent.
        $legacy = @unserialize($raw, ['allowed_classes' => false]);

        return $legacy === false ? null : $legacy;
    }

    private function firstLine(string $message): string
    {
        $line = strtok(trim($message), "\n") ?: '';

        return mb_strlen($line) > 160 ? mb_substr($line, 0, 160) . '…' : $line;
    }
}
