<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\InstanceFactory;
use Magento\Framework\Cache\ConfigInterface as CacheConfig;
use Magento\Framework\Cache\FrontendInterface;
use Psr\Log\LoggerInterface;

/**
 * Proves a flush actually emptied something.
 *
 * Every cache backend answers "cleaned" the same way whether it removed a million entries
 * or silently did nothing — a read-only cache directory, a Redis instance that has gone
 * away, a tag that matches no keys. The button then reports success and the developer goes
 * looking for the problem in their own code, which is the worst possible outcome for a tool
 * whose entire job is to remove that doubt.
 *
 * So we do not take the backend's word for it. A probe entry is written immediately before
 * the flush and read back immediately after: if it survived, the flush did not happen, and
 * the panel says so instead of showing a tick.
 *
 * Which object the probe is written through matters more than it looks. A per-type flush
 * cleans by *tag*, and only the cache type's own instance — the TagScope-decorated frontend
 * that Magento\Framework\App\Cache\TypeList resolves — attaches that tag on save. Writing the
 * probe through the frontend pool instead produces an untagged entry that survives a
 * perfectly good flush, and the verifier then cries wolf on every click. So the type
 * instance is resolved exactly the way TypeList resolves it.
 */
class FlushVerifier
{
    /**
     * Short-lived so a probe left behind by a fatal error cannot outlive the request that
     * wrote it by more than a minute.
     */
    private const PROBE_LIFETIME = 60;

    private const PROBE_VALUE = 'modracx-flush-probe';

    public function __construct(
        private readonly CacheConfig $cacheConfig,
        private readonly InstanceFactory $instanceFactory,
        private readonly Pool $frontendPool,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Seed a probe into one cache type and return the id to check afterwards.
     */
    public function probeType(string $cacheType): ?string
    {
        try {
            $id = $this->newId();
            $this->typeInstance($cacheType)->save(self::PROBE_VALUE, $id, [], self::PROBE_LIFETIME);

            return $id;
        } catch (\Throwable $e) {
            $this->logger->debug('Modracx_AdminDevTools could not seed a cache probe: ' . $e->getMessage());

            return null;
        }
    }

    public function verifyType(string $cacheType, ?string $probeId): ?bool
    {
        if ($probeId === null) {
            return null;
        }

        try {
            return $this->typeInstance($cacheType)->load($probeId) === false;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The same object TypeList::cleanType() acts on, resolved the same way.
     */
    private function typeInstance(string $cacheType): FrontendInterface
    {
        $config = $this->cacheConfig->getType($cacheType);

        return $this->instanceFactory->get($config['instance']);
    }

    /**
     * Seed a probe into every configured cache frontend.
     *
     * Used by the storage-wide actions, which are supposed to leave nothing behind anywhere.
     * On a normal installation that is two frontends — the default one and the separate
     * page cache — and missing the second is precisely how a storefront carries on serving
     * the page you thought you had just flushed.
     *
     * @return array<string, string>
     */
    public function probeAllFrontends(): array
    {
        $probes = [];

        try {
            foreach ($this->frontendPool as $name => $frontend) {
                $id = $this->newId();
                $frontend->save(self::PROBE_VALUE, $id, [], self::PROBE_LIFETIME);
                $probes[(string)$name] = $id;
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Modracx_AdminDevTools could not seed cache probes: ' . $e->getMessage());
        }

        return $probes;
    }

    /**
     * @param array<string, string> $probes
     * @return list<string> names of frontends that still hold their probe
     */
    public function survivors(array $probes): array
    {
        $survivors = [];

        try {
            foreach ($this->frontendPool as $name => $frontend) {
                $id = $probes[(string)$name] ?? null;

                if ($id !== null && $frontend->load($id) !== false) {
                    $survivors[] = (string)$name;
                }
            }
        } catch (\Throwable) {
            return $survivors;
        }

        return $survivors;
    }

    /**
     * Human-readable outcome to append to a success message.
     *
     * Deliberately says nothing when the flush verified cleanly — a developer flushing a
     * cache for the fourth time in a minute does not need to be congratulated, they need to
     * be told when it did not work.
     */
    public function note(?bool $verified, array $survivors = []): string
    {
        if ($survivors !== []) {
            return ' ' . (string)__(
                'Warning: %1 still held its test entry afterwards, so it was not actually emptied. '
                . 'Check that the cache directory is writable by the web server user.',
                implode(', ', $survivors)
            );
        }

        if ($verified === false) {
            return ' ' . (string)__(
                'Warning: the cache reported success but a test entry survived, so nothing was '
                . 'actually removed. Check that the cache directory is writable by the web server user.'
            );
        }

        return '';
    }

    private function newId(): string
    {
        return 'modracx_flush_probe_' . bin2hex(random_bytes(8));
    }
}
