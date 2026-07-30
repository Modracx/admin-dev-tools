<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Framework\App\ObjectManager\ConfigLoader;
use Magento\Framework\Event\Config\Reader as EventReader;
use Magento\Framework\Exception\LocalizedException;

/**
 * Answers "why is this class not mine?" — the resolved preference, every plugin that
 * intercepts it, the virtual types built on it, and, for an event, its observers.
 *
 * Read straight out of Magento's own merged configuration, one area at a time, the same
 * way the Cache and Index tabs read the cache and indexer registries: no hardcoded list,
 * so a third-party module's plugin appears here the moment it is enabled.
 *
 * Areas are read explicitly rather than relying on the current request's scope. A
 * question about a plugin is almost always a question about the frontend, and this panel
 * is only ever open in the admin.
 */
class WiringInspector
{
    /**
     * Configuration scopes in the order a developer thinks about them. 'primary' is
     * omitted: it holds bootstrap wiring that no one is ever asking about here.
     */
    private const AREAS = ['global', 'frontend', 'adminhtml', 'crontab', 'webapi_rest', 'webapi_soap', 'graphql'];

    private const NAME_PATTERN = '~^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$~';

    private const EVENT_PATTERN = '~^[a-z0-9_]+$~i';

    /** @var array<string, array<string, mixed>> */
    private array $configs = [];

    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly EventReader $eventReader
    ) {
    }

    /**
     * @return array{
     *     type: string, exists: bool, kind: string, ancestry: array<int, string>,
     *     preferences: array<int, array{area: string, to: string}>,
     *     plugins: array<int, array{area: string, declared_on: string, name: string, instance: string, sort_order: int, disabled: bool, methods: array<int, string>}>,
     *     virtual_types: array<int, array{area: string, name: string, base: string}>
     * }
     * @throws LocalizedException
     */
    public function inspectType(string $type): array
    {
        $type = ltrim(trim($type), '\\');

        if ($type === '' || !preg_match(self::NAME_PATTERN, $type)) {
            throw new LocalizedException(
                __('Enter a class or interface name, such as Magento\Catalog\Api\ProductRepositoryInterface.')
            );
        }

        $exists = class_exists($type) || interface_exists($type);
        $kind   = interface_exists($type) ? (string) __('interface') : (string) __('class');

        // A plugin declared on an interface or a parent class runs on the concrete class
        // too, which is exactly the case people are usually hunting. Walk the ancestry and
        // report which ancestor each plugin was declared on.
        $ancestry = $this->ancestry($type, $exists);

        $preferences   = [];
        $plugins       = [];
        $virtualTypes  = [];

        foreach (self::AREAS as $area) {
            $config = $this->config($area);

            if (isset($config['preferences'][$type])) {
                $preferences[] = ['area' => $area, 'to' => (string) $config['preferences'][$type]];
            }

            foreach ($ancestry as $ancestor) {
                foreach ($config[$ancestor]['plugins'] ?? [] as $name => $plugin) {
                    if (!is_array($plugin) || empty($plugin['instance'])) {
                        continue;
                    }

                    $plugins[] = [
                        'area'        => $area,
                        'declared_on' => $ancestor,
                        'name'        => (string) $name,
                        'instance'    => (string) $plugin['instance'],
                        'sort_order'  => (int) ($plugin['sortOrder'] ?? 0),
                        'disabled'    => !empty($plugin['disabled']),
                        'methods'     => $this->interceptedMethods((string) $plugin['instance']),
                    ];
                }
            }

            foreach ($config as $name => $node) {
                if (is_array($node) && isset($node['type']) && ltrim((string) $node['type'], '\\') === $type) {
                    $virtualTypes[] = ['area' => $area, 'name' => (string) $name, 'base' => $type];
                }
            }
        }

        // Sorted the way the interceptor runs them: lower sortOrder first.
        usort($plugins, static fn (array $a, array $b): int =>
            [$a['area'], $a['sort_order'], $a['name']] <=> [$b['area'], $b['sort_order'], $b['name']]);

        return [
            'type'          => $type,
            'exists'        => $exists,
            'kind'          => $kind,
            'ancestry'      => $ancestry,
            'preferences'   => $preferences,
            'plugins'       => $plugins,
            'virtual_types' => $virtualTypes,
        ];
    }

    /**
     * @return array{event: string, observers: array<int, array{area: string, name: string, instance: string, disabled: bool, shared: bool}>}
     * @throws LocalizedException
     */
    public function inspectEvent(string $event): array
    {
        $event = trim($event);

        if ($event === '' || !preg_match(self::EVENT_PATTERN, $event)) {
            throw new LocalizedException(
                __('Enter an event name, such as sales_order_place_after.')
            );
        }

        $observers = [];

        foreach (self::AREAS as $area) {
            foreach ($this->events($area)[$event] ?? [] as $name => $observer) {
                $observers[] = [
                    'area'     => $area,
                    'name'     => (string) ($observer['name'] ?? $name),
                    'instance' => (string) ($observer['instance'] ?? ''),
                    'disabled' => !empty($observer['disabled']),
                    'shared'   => (bool) ($observer['shared'] ?? true),
                ];
            }
        }

        return ['event' => $event, 'observers' => $observers];
    }

    /**
     * The type itself, its parent classes and its interfaces — every name a plugin could
     * have been declared on and still reach this type.
     *
     * @return array<int, string>
     */
    private function ancestry(string $type, bool $exists): array
    {
        if (!$exists) {
            return [$type];
        }

        $names = array_merge([$type], array_values(class_parents($type) ?: []), array_values(class_implements($type) ?: []));

        return array_values(array_unique(array_map(static fn (string $n): string => ltrim($n, '\\'), $names)));
    }

    /**
     * Which methods a plugin actually intercepts, read off its own public methods.
     *
     * beforeSave/aroundSave/afterSave all intercept save(); anything else on the class is
     * just a method. Cheap reflection on one class, and it turns "there are six plugins"
     * into "there are six plugins and two of them touch the method you are debugging".
     *
     * @return array<int, string>
     */
    private function interceptedMethods(string $pluginClass): array
    {
        if (!class_exists($pluginClass)) {
            return [];
        }

        try {
            $reflection = new \ReflectionClass($pluginClass);
        } catch (\ReflectionException) {
            return [];
        }

        $methods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!preg_match('~^(before|around|after)([A-Z]\w*)$~', $method->getName(), $match)) {
                continue;
            }

            $methods[lcfirst($match[2])][] = $match[1];
        }

        $out = [];
        foreach ($methods as $target => $kinds) {
            sort($kinds);
            $out[] = $target . '() — ' . implode(', ', $kinds);
        }
        sort($out);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(string $area): array
    {
        if (!isset($this->configs[$area])) {
            try {
                $this->configs[$area] = $this->configLoader->load($area);
            } catch (\Exception $e) {
                $this->configs[$area] = [];
            }
        }

        return $this->configs[$area];
    }

    /**
     * @return array<string, mixed>
     */
    private function events(string $area): array
    {
        try {
            return $this->eventReader->read($area);
        } catch (\Exception $e) {
            return [];
        }
    }
}
