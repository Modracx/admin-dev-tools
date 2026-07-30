<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Filesystem;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Module\ModuleListInterface;

/**
 * What this installation actually is — the questions asked at the start of every support
 * conversation, answered from the running application rather than from a wiki page that
 * was accurate two deploys ago.
 *
 * Nothing here is a secret: hosts and ports are named, credentials never are.
 */
class Environment
{
    /** A search engine that does not answer in this long is down as far as a page render cares. */
    private const PING_TIMEOUT = 2;

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resource,
        private readonly ModuleListInterface $moduleList,
        private readonly Filesystem $filesystem,
        private readonly State $appState,
        private readonly Curl $curl
    ) {
    }

    /**
     * @return array<string, array<int, array{label: string, value: string, flag: string}>>
     */
    public function getSections(): array
    {
        return [
            (string) __('Application') => $this->application(),
            (string) __('Database')    => $this->database(),
            (string) __('Storage')     => $this->storage(),
            (string) __('Search')      => $this->search(),
            (string) __('Queue')       => $this->queue(),
        ];
    }

    /**
     * @return array<int, array{label: string, value: string, flag: string}>
     */
    private function application(): array
    {
        $mode = $this->mode();

        return [
            $this->row(__('Magento'), sprintf(
                '%s %s',
                $this->productMetadata->getEdition(),
                $this->productMetadata->getVersion()
            )),
            $this->row(__('Mode'), $mode, $mode === State::MODE_DEFAULT ? 'warn' : ''),
            $this->row(__('PHP'), PHP_VERSION),
            $this->row(__('Memory limit'), (string) ini_get('memory_limit')),
            $this->row(__('Max execution time'), ini_get('max_execution_time') . 's'),
            $this->row(__('OPcache'), $this->opcache(), $this->opcache() === (string) __('off') ? 'warn' : ''),
            $this->row(__('Xdebug'), extension_loaded('xdebug') ? (string) __('loaded') : (string) __('not loaded'),
                extension_loaded('xdebug') ? 'warn' : ''),
            $this->row(__('Modules enabled'), (string) count($this->moduleList->getNames())),
            $this->row(__('Static content version'), $this->deployedVersion()),
            $this->row(__('Admin path'), (string) $this->deploymentConfig->get('backend/frontName')),
            $this->row(__('Timezone'), (string) $this->scopeConfig->getValue('general/locale/timezone')),
            $this->row(__('Base URL'), (string) $this->scopeConfig->getValue('web/unsecure/base_url')),
            $this->row(__('Secure base URL'), (string) $this->scopeConfig->getValue('web/secure/base_url')),
        ];
    }

    /**
     * @return array<int, array{label: string, value: string, flag: string}>
     */
    private function database(): array
    {
        $connection = $this->resource->getConnection();

        try {
            $version = (string) $connection->fetchOne('SELECT VERSION()');
        } catch (\Exception $e) {
            $version = (string) __('unavailable');
        }

        $config = (array) $this->deploymentConfig->get('db/connection/default');

        return [
            $this->row(__('Server'), $version),
            $this->row(__('Host'), (string) ($config['host'] ?? '')),
            $this->row(__('Schema'), (string) ($config['dbname'] ?? '')),
            $this->row(__('Table prefix'), (string) ($this->deploymentConfig->get('db/table_prefix') ?: __('none'))),
            $this->row(__('Connections'), implode(', ', array_keys((array) $this->deploymentConfig->get('db/connection')))),
        ];
    }

    /**
     * @return array<int, array{label: string, value: string, flag: string}>
     */
    private function storage(): array
    {
        $rows = [];

        foreach (['default' => __('Cache backend'), 'page_cache' => __('Page cache backend')] as $frontend => $label) {
            $backend = (string) ($this->deploymentConfig->get("cache/frontend/{$frontend}/backend") ?: '');

            if ($backend === '') {
                $rows[] = $this->row($label, (string) __('file (default)'));
                continue;
            }

            $options = (array) $this->deploymentConfig->get("cache/frontend/{$frontend}/backend_options");
            $rows[]  = $this->row($label, $this->describeBackend($backend, $options));
        }

        $sessionSave = (string) ($this->deploymentConfig->get('session/save') ?: 'files');
        $sessionHost = (string) ($this->deploymentConfig->get('session/redis/host') ?: '');
        $rows[] = $this->row(
            __('Sessions'),
            $sessionHost !== ''
                ? sprintf('%s — %s, db %s', $sessionSave, $sessionHost, (string) $this->deploymentConfig->get('session/redis/database'))
                : $sessionSave
        );

        try {
            $rows[] = $this->row(
                __('var/ writable'),
                is_writable($this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR)->getAbsolutePath())
                    ? (string) __('yes')
                    : (string) __('no'),
                is_writable($this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR)->getAbsolutePath()) ? '' : 'err'
            );
        } catch (\Exception $e) {
            $rows[] = $this->row(__('var/ writable'), (string) __('unknown'), 'warn');
        }

        return $rows;
    }

    /**
     * Search is the one thing here worth actually contacting: a misconfigured engine looks
     * identical to a working one in config, and only differs when something asks it a
     * question. On 2.4 a store with no reachable engine cannot render a category page.
     *
     * @return array<int, array{label: string, value: string, flag: string}>
     */
    private function search(): array
    {
        $engine = (string) ($this->scopeConfig->getValue('catalog/search/engine') ?: 'unknown');
        $prefix = 'catalog/search/' . $engine . '_server_';
        $host   = (string) $this->scopeConfig->getValue($prefix . 'hostname');
        $port   = (string) $this->scopeConfig->getValue($prefix . 'port');

        $rows = [
            $this->row(__('Engine'), $engine),
            $this->row(__('Host'), $host !== '' ? $host . ($port !== '' ? ':' . $port : '') : (string) __('not configured'),
                $host === '' ? 'err' : ''),
        ];

        if ($host === '') {
            return $rows;
        }

        $ping = $this->ping($host, $port);
        $rows[] = $this->row(__('Reachable'), $ping['message'], $ping['ok'] ? '' : 'err');

        return $rows;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function ping(string $host, string $port): array
    {
        $scheme = str_starts_with($host, 'http') ? '' : 'http://';
        $url    = rtrim($scheme . $host . ($port !== '' ? ':' . $port : ''), '/') . '/_cluster/health';

        try {
            $this->curl->setTimeout(self::PING_TIMEOUT);
            $this->curl->get($url);

            $status = $this->curl->getStatus();
            $body   = json_decode((string) $this->curl->getBody(), true);

            if ($status !== 200 || !is_array($body)) {
                return ['ok' => false, 'message' => (string) __('HTTP %1 from %2', $status, $url)];
            }

            return [
                'ok'      => ($body['status'] ?? '') !== 'red',
                'message' => (string) __(
                    'cluster %1, status %2, %3 node(s)',
                    $body['cluster_name'] ?? '?',
                    $body['status'] ?? '?',
                    $body['number_of_nodes'] ?? '?'
                ),
            ];
        } catch (\Exception $e) {
            return ['ok' => false, 'message' => (string) __('no answer (%1)', $e->getMessage())];
        }
    }

    /**
     * @return array<int, array{label: string, value: string, flag: string}>
     */
    private function queue(): array
    {
        $amqp = (array) $this->deploymentConfig->get('queue/amqp');
        $consumersWait = $this->deploymentConfig->get('queue/consumers_wait_for_messages');

        return [
            $this->row(
                __('Transport'),
                $amqp !== [] && !empty($amqp['host'])
                    ? sprintf('AMQP — %s:%s', $amqp['host'], $amqp['port'] ?? '5672')
                    : (string) __('MySQL tables')
            ),
            $this->row(
                __('Consumers runner'),
                $this->deploymentConfig->get('cron_consumers_runner/cron_run') === false
                    ? (string) __('disabled — consumers must run as separate processes')
                    : (string) __('enabled — started by cron')
            ),
            $this->row(__('Consumers wait for messages'), $consumersWait === null ? '1' : (string) (int) $consumersWait),
        ];
    }

    private function describeBackend(string $backend, array $options): string
    {
        if (stripos($backend, 'redis') === false) {
            return $backend;
        }

        return sprintf(
            'Redis — %s:%s, db %s',
            $options['server'] ?? '?',
            $options['port'] ?? '6379',
            $options['database'] ?? '0'
        );
    }

    private function deployedVersion(): string
    {
        try {
            $static = $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW);

            return $static->isExist('deployed_version.txt')
                ? trim($static->readFile('deployed_version.txt'))
                : (string) __('not deployed');
        } catch (\Exception $e) {
            return (string) __('unknown');
        }
    }

    private function opcache(): string
    {
        if (!function_exists('opcache_get_status')) {
            return (string) __('off');
        }

        $status = @opcache_get_status(false);

        return is_array($status) && !empty($status['opcache_enabled'])
            ? (string) __('enabled')
            : (string) __('off');
    }

    private function mode(): string
    {
        try {
            return $this->appState->getMode();
        } catch (\Exception $e) {
            return State::MODE_DEFAULT;
        }
    }

    /**
     * @return array{label: string, value: string, flag: string}
     */
    private function row(string|\Magento\Framework\Phrase $label, string $value, string $flag = ''): array
    {
        return ['label' => (string) $label, 'value' => $value, 'flag' => $flag];
    }
}
