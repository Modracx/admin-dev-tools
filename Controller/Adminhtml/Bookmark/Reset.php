<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Bookmark;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\BookmarkTool;

class Reset extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::grid_bookmarks';

    public function __construct(
        Context $context,
        BlockFactory $blockFactory,
        JsonFactory $jsonFactory,
        private readonly BookmarkTool $bookmarkTool
    ) {
        parent::__construct($context, $blockFactory, $jsonFactory);
    }

    public function execute()
    {
        $namespace = (string) $this->getRequest()->getParam('namespace');

        try {
            $deleted = $this->bookmarkTool->reset($namespace);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }

        return $this->success(
            (string) __('Reset %1 saved view(s) for "%2". Reload the grid to see the default layout.', $deleted, $namespace)
        );
    }
}
