<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;

/**
 * The dev/* switches every Magento developer flips a dozen times a day, without the
 * SSH → config:set → cache:flush round trip.
 *
 * Everything here writes the default scope only. These are development switches: a
 * per-store template hint is almost always a mistake, and offering the scope selector
 * would invite one.
 */
class DevFlags
{
    /**
     * Flags in display order.
     *
     * `static` marks a flag that changes how static assets are generated rather than how
     * a request is served — in production those assets are already deployed, so flipping
     * one does nothing until static content is redeployed.
     */
    private const FLAGS = [
        [
            'key'    => 'hints_storefront',
            'label'  => 'Template hints — storefront',
            'path'   => 'dev/debug/template_hints_storefront',
            'group'  => 'debug',
            'note'   => 'Outlines every template on the storefront with its path.',
            'static' => false,
        ],
        [
            'key'    => 'hints_blocks',
            'label'  => 'Template hints — block names',
            'path'   => 'dev/debug/template_hints_blocks',
            'group'  => 'debug',
            'note'   => 'Adds the block class and layout name to each hint.',
            'static' => false,
        ],
        [
            'key'    => 'hints_admin',
            'label'  => 'Template hints — admin',
            'path'   => 'dev/debug/template_hints_admin',
            'group'  => 'debug',
            'note'   => 'The same outlines in the admin. Expect the layout to shift.',
            'static' => false,
        ],
        [
            'key'    => 'hints_with_param',
            'label'  => 'Hints only with ?templatehints=…',
            'path'   => 'dev/debug/template_hints_storefront_show_with_parameter',
            'group'  => 'debug',
            'note'   => 'Keeps hints off for shoppers: they appear only when the URL carries the secret parameter set in Stores → Configuration → Advanced → Developer.',
            'static' => false,
        ],
        [
            'key'    => 'debug_logging',
            'label'  => 'Debug logging',
            'path'   => 'dev/debug/debug_logging',
            'group'  => 'debug',
            'note'   => 'Writes var/log/debug.log. Read it from the Logs tab.',
            'static' => false,
        ],
        [
            'key'    => 'translate_inline',
            'label'  => 'Inline translation — storefront',
            'path'   => 'dev/translate_inline/active',
            'group'  => 'debug',
            'note'   => 'Needs the full page cache disabled to show anything.',
            'static' => false,
        ],
        [
            'key'    => 'translate_inline_admin',
            'label'  => 'Inline translation — admin',
            'path'   => 'dev/translate_inline/active_admin',
            'group'  => 'debug',
            'note'   => '',
            'static' => false,
        ],
        [
            'key'    => 'js_merge',
            'label'  => 'Merge JavaScript files',
            'path'   => 'dev/js/merge_files',
            'group'  => 'static',
            'note'   => '',
            'static' => true,
        ],
        [
            'key'    => 'js_minify',
            'label'  => 'Minify JavaScript files',
            'path'   => 'dev/js/minify_files',
            'group'  => 'static',
            'note'   => 'Minified JS is what makes a stack trace unreadable.',
            'static' => true,
        ],
        [
            'key'    => 'js_bundling',
            'label'  => 'JavaScript bundling',
            'path'   => 'dev/js/enable_js_bundling',
            'group'  => 'static',
            'note'   => '',
            'static' => true,
        ],
        [
            'key'    => 'css_merge',
            'label'  => 'Merge CSS files',
            'path'   => 'dev/css/merge_css_files',
            'group'  => 'static',
            'note'   => '',
            'static' => true,
        ],
        [
            'key'    => 'css_minify',
            'label'  => 'Minify CSS files',
            'path'   => 'dev/css/minify_files',
            'group'  => 'static',
            'note'   => '',
            'static' => true,
        ],
        [
            'key'    => 'html_minify',
            'label'  => 'Minify HTML',
            'path'   => 'dev/template/minify_html',
            'group'  => 'static',
            'note'   => 'Minified HTML makes template hints much harder to read.',
            'static' => true,
        ],
        [
            'key'    => 'static_sign',
            'label'  => 'Sign static files',
            'path'   => 'dev/static/sign',
            'group'  => 'static',
            'note'   => 'Adds the version segment to static URLs. Turning it off makes a stale asset survive a browser cache.',
            'static' => true,
        ],
    ];

    /** Restricting dev output by IP silently disables template hints for everyone else. */
    private const ALLOW_IPS_PATH = 'dev/restrict/allow_ips';

    private const CACHE_TYPE = 'config';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly SettingChecker $settingChecker,
        private readonly FlushVerifier $verifier,
        private readonly State $appState
    ) {
    }

    /**
     * @return array<int, array{
     *     key: string, label: string, path: string, group: string, note: string,
     *     enabled: bool, locked: bool, static: bool
     * }>
     */
    public function getFlags(): array
    {
        $flags = [];

        foreach (self::FLAGS as $flag) {
            $flags[] = $flag + [
                'enabled' => (bool) $this->scopeConfig->getValue(
                    $flag['path'],
                    ScopeConfigInterface::SCOPE_TYPE_DEFAULT
                ),
                'locked'  => $this->settingChecker->isReadOnly(
                    $flag['path'],
                    ScopeConfigInterface::SCOPE_TYPE_DEFAULT
                ),
            ];
        }

        return $flags;
    }

    /**
     * Whatever the developer needs to know before reading the switches above as truth.
     *
     * @return array<int, string>
     */
    public function getWarnings(): array
    {
        $warnings = [];

        $allowedIps = trim((string) $this->scopeConfig->getValue(self::ALLOW_IPS_PATH));
        if ($allowedIps !== '') {
            $warnings[] = (string) __(
                'Developer output is restricted to %1 (%2). Template hints stay invisible from any other address no matter what these switches say.',
                $allowedIps,
                self::ALLOW_IPS_PATH
            );
        }

        if ($this->isProduction()) {
            // Hints themselves are not mode-gated — Magento_Developer's DebugHints plugin
            // reads the config value and checks the IP restriction, nothing else. What
            // production changes is that the admin hides the Developer section (which is
            // why this panel is useful there) and that static assets are pre-deployed.
            $warnings[] = (string) __(
                'Production mode: the admin hides Stores → Configuration → Advanced → Developer, but these values are still read at runtime. The asset switches below need setup:static-content:deploy before they change anything.'
            );
        }

        return $warnings;
    }

    public function getMode(): string
    {
        try {
            return $this->appState->getMode();
        } catch (\Exception $e) {
            return State::MODE_DEFAULT;
        }
    }

    public function isProduction(): bool
    {
        return $this->getMode() === State::MODE_PRODUCTION;
    }

    /**
     * Flip one flag, flush the config cache, and check the flush actually happened.
     *
     * A dev flag that reports success while the old value is still cached is the worst
     * possible outcome: the next twenty minutes go on wondering why hints are not showing.
     *
     * @throws LocalizedException
     */
    public function toggle(string $key, bool $enable): string
    {
        $flag = $this->find($key);

        if ($this->settingChecker->isReadOnly($flag['path'], ScopeConfigInterface::SCOPE_TYPE_DEFAULT)) {
            throw new LocalizedException(
                __(
                    '%1 is fixed in app/etc/config.php, app/etc/env.php or an environment variable. Change it there — a database write would be ignored.',
                    $flag['path']
                )
            );
        }

        $this->configWriter->save(
            $flag['path'],
            $enable ? '1' : '0',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            0
        );

        // Probe before the clean, read back after: same contract as the Cache tab.
        $probe = $this->verifier->probeType(self::CACHE_TYPE);
        $this->cacheTypeList->cleanType(self::CACHE_TYPE);
        $verified = $this->verifier->verifyType(self::CACHE_TYPE, $probe);

        // In-process config is now stale — reload it so the value this same request
        // renders back into the panel is the one that was just written.
        $this->reinitableConfig->reinit();

        $message = $enable
            ? (string) __('%1 enabled.', $flag['label'])
            : (string) __('%1 disabled.', $flag['label']);

        if (!empty($flag['static']) && $this->isProduction()) {
            $message .= ' ' . (string) __('Run setup:static-content:deploy for this to reach the browser.');
        }

        return $message . $this->verifier->note($verified);
    }

    /**
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function find(string $key): array
    {
        foreach (self::FLAGS as $flag) {
            if ($flag['key'] === $key) {
                return $flag;
            }
        }

        throw new LocalizedException(__('Unknown flag "%1".', $key));
    }
}
