<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Catalog\Model\Product\ImageFactory;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\State\CleanupFiles;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Asset\MergeService;

/**
 * The "Additional Cache Management" actions from the core Cache Management page.
 *
 * Each action mirrors its Magento\Backend\Controller\Adminhtml\Cache counterpart —
 * same work, same dispatched event, same ACL resource — so behaviour and permissions
 * stay identical to flushing from System > Cache Management.
 */
class CacheAction
{
    public const IMAGES  = 'catalog_images';
    public const JS_CSS  = 'js_css';
    public const STATIC_FILES = 'static_files';
    public const STORAGE = 'cache_storage';
    public const SYSTEM  = 'magento_cache';

    private const ACTIONS = [
        self::SYSTEM => [
            'label'       => 'Flush Magento Cache',
            'description' => 'Cleans all enabled cache types',
            'resource'    => 'Magento_Backend::flush_magento_cache',
        ],
        self::STORAGE => [
            'label'       => 'Flush Cache Storage',
            'description' => 'Purges the whole cache backend, including non-Magento entries',
            'resource'    => 'Magento_Backend::flush_cache_storage',
        ],
        self::IMAGES => [
            'label'       => 'Flush Catalog Images Cache',
            'description' => 'Pregenerated product images files',
            'resource'    => 'Magento_Backend::flush_catalog_images',
        ],
        self::JS_CSS => [
            'label'       => 'Flush JavaScript/CSS Cache',
            'description' => 'Themes JavaScript and CSS files combined to one file',
            'resource'    => 'Magento_Backend::flush_js_css',
        ],
        self::STATIC_FILES => [
            'label'       => 'Flush Static Files Cache',
            'description' => 'Preprocessed view files and static files',
            'resource'    => 'Magento_Backend::flush_static_files',
        ],
    ];

    public function __construct(
        private readonly ImageFactory $productImageFactory,
        private readonly MergeService $mergeService,
        private readonly CleanupFiles $cleanupFiles,
        private readonly Pool $cacheFrontendPool,
        private readonly EventManager $eventManager
    ) {
    }

    /**
     * All actions, in display order.
     *
     * @return array<string, array{label: string, description: string, resource: string}>
     */
    public function getActions(): array
    {
        return self::ACTIONS;
    }

    public function has(string $id): bool
    {
        return isset(self::ACTIONS[$id]);
    }

    /**
     * ACL resource guarding the action, or null if the action does not exist.
     */
    public function getResource(string $id): ?string
    {
        return self::ACTIONS[$id]['resource'] ?? null;
    }

    /**
     * Run the action and return the success message.
     *
     * @throws LocalizedException
     */
    public function execute(string $id): string
    {
        switch ($id) {
            case self::SYSTEM:
                foreach ($this->cacheFrontendPool as $cacheFrontend) {
                    $cacheFrontend->clean();
                }
                $this->eventManager->dispatch('adminhtml_cache_flush_system');
                return (string) __('The Magento cache storage has been flushed.');

            case self::STORAGE:
                $this->eventManager->dispatch('adminhtml_cache_flush_all');
                foreach ($this->cacheFrontendPool as $cacheFrontend) {
                    $cacheFrontend->getBackend()->clean();
                }
                return (string) __('You flushed the cache storage.');

            case self::IMAGES:
                $this->productImageFactory->create()->clearCache();
                $this->eventManager->dispatch('clean_catalog_images_cache_after');
                return (string) __('The image cache was cleaned.');

            case self::JS_CSS:
                $this->mergeService->cleanMergedJsCss();
                $this->eventManager->dispatch('clean_media_cache_after');
                return (string) __('The JavaScript/CSS cache has been cleaned.');

            case self::STATIC_FILES:
                $this->cleanupFiles->clearMaterializedViewFiles();
                $this->eventManager->dispatch('clean_static_files_cache_after');
                return (string) __('The static files cache has been cleaned.');

            default:
                throw new LocalizedException(__('Unknown cache action.'));
        }
    }
}
