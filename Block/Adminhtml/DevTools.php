<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Block\Adminhtml;

use Magento\Backend\Block\Template;

/**
 * The single toolbar entry point.
 *
 * Renders one "Dev" button and the tab shell behind it — no data, no queries. Every tab
 * loads its contents from its own controller the first time it is selected, so an
 * ordinary admin page render costs nothing beyond this markup.
 */
class DevTools extends Template
{
    protected $_template = 'Modracx_AdminDevTools::devbar_tools.phtml';

    /**
     * Tabs in display order, filtered to what the current role may see.
     *
     * @return array<int, array{id: string, label: string, url: string}>
     */
    public function getTabs(): array
    {
        $definitions = [
            ['id' => 'cache',   'label' => __('Cache'),   'route' => 'modracx_devtools/cache/panel',    'acl' => 'Modracx_AdminDevTools::cache_flush'],
            ['id' => 'index',   'label' => __('Index'),   'route' => 'modracx_devtools/indexer/panel',  'acl' => 'Modracx_AdminDevTools::reindex'],
            ['id' => 'flags',   'label' => __('Flags'),   'route' => 'modracx_devtools/flags/index',    'acl' => 'Modracx_AdminDevTools::flags'],
            ['id' => 'wiring',  'label' => __('Wiring'),  'route' => 'modracx_devtools/wiring/index',   'acl' => 'Modracx_AdminDevTools::wiring'],
            ['id' => 'env',     'label' => __('Env'),     'route' => 'modracx_devtools/environment/index', 'acl' => 'Modracx_AdminDevTools::environment'],
            ['id' => 'mail',    'label' => __('Mail'),    'route' => 'modracx_devtools/mail/index',     'acl' => 'Modracx_AdminDevTools::mail'],
            ['id' => 'urls',    'label' => __('URLs'),    'route' => 'modracx_devtools/rewrite/index',  'acl' => 'Modracx_AdminDevTools::rewrites'],
            ['id' => 'db',      'label' => __('DB'),      'route' => 'modracx_devtools/database/index', 'acl' => 'Modracx_AdminDevTools::database'],
            ['id' => 'logs',    'label' => __('Logs'),    'route' => 'modracx_devtools/log/view',       'acl' => 'Modracx_AdminDevTools::logs'],
            ['id' => 'reports', 'label' => __('Reports'), 'route' => 'modracx_devtools/report/index',   'acl' => 'Modracx_AdminDevTools::reports'],
            ['id' => 'activity','label' => __('Activity'),'route' => 'modracx_devtools/activity/index','acl' => 'Modracx_AdminDevTools::activity'],
            ['id' => 'cron',    'label' => __('Cron'),    'route' => 'modracx_devtools/cron/status',    'acl' => 'Modracx_AdminDevTools::cron_health'],
            ['id' => 'config',  'label' => __('Config'),  'route' => 'modracx_devtools/config/recent',  'acl' => 'Modracx_AdminDevTools::config_inspect'],
            ['id' => 'lookup',  'label' => __('Lookup'),  'route' => 'modracx_devtools/config/lookup',  'acl' => 'Modracx_AdminDevTools::config_inspect'],
            ['id' => 'grids',   'label' => __('Grids'),   'route' => 'modracx_devtools/bookmark/index', 'acl' => 'Modracx_AdminDevTools::grid_bookmarks'],
            ['id' => 'modules', 'label' => __('Modules'), 'route' => 'modracx_devtools/module/index',   'acl' => 'Modracx_AdminDevTools::modules'],
        ];

        $tabs = [];
        foreach ($definitions as $tab) {
            if (!$this->_authorization->isAllowed($tab['acl'])) {
                continue;
            }
            $tabs[] = [
                'id'    => $tab['id'],
                'label' => (string) $tab['label'],
                'url'   => $this->getUrl($tab['route']),
            ];
        }

        return $tabs;
    }

    public function canSeeCronBadge(): bool
    {
        return $this->_authorization->isAllowed('Modracx_AdminDevTools::cron_health');
    }

    public function getCronBadgeUrl(): string
    {
        return $this->getUrl('modracx_devtools/cron/badge');
    }

    /**
     * Action endpoints the panels post back to.
     *
     * @return array<string, string>
     */
    public function getActionUrls(): array
    {
        return [
            'cacheFlush'    => $this->getUrl('modracx_devtools/cache/flush'),
            'cacheAction'   => $this->getUrl('modracx_devtools/cache/run'),
            'indexerRun'    => $this->getUrl('modracx_devtools/indexer/run'),
            'logView'       => $this->getUrl('modracx_devtools/log/view'),
            'logClear'      => $this->getUrl('modracx_devtools/log/clear'),
            'reportView'    => $this->getUrl('modracx_devtools/report/view'),
            'configLookup'  => $this->getUrl('modracx_devtools/config/lookup'),
            'bookmarkReset' => $this->getUrl('modracx_devtools/bookmark/reset'),
            'moduleToggle'  => $this->getUrl('modracx_devtools/module/toggle'),
            'cronRun'       => $this->getUrl('modracx_devtools/cron/run'),
            'flagToggle'    => $this->getUrl('modracx_devtools/flags/toggle'),
            'wiringIndex'   => $this->getUrl('modracx_devtools/wiring/index'),
            'indexerMode'   => $this->getUrl('modracx_devtools/indexer/mode'),
            'mailView'      => $this->getUrl('modracx_devtools/mail/view'),
            'mailClear'     => $this->getUrl('modracx_devtools/mail/clear'),
            'activityIndex' => $this->getUrl('modracx_devtools/activity/index'),
            'activityClear' => $this->getUrl('modracx_devtools/activity/clear'),
        ];
    }
}
