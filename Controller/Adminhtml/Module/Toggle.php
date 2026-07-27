<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Module;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\ModuleToggle;

/**
 * Enable or disable one module. Guarded by its own ACL, separate from viewing the list —
 * seeing module status is harmless, changing it is not.
 */
class Toggle extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::modules_toggle';

    public function __construct(
        Context $context,
        BlockFactory $blockFactory,
        JsonFactory $jsonFactory,
        private readonly ModuleToggle $moduleToggle
    ) {
        parent::__construct($context, $blockFactory, $jsonFactory);
    }

    public function execute()
    {
        $module = (string) $this->getRequest()->getParam('module');
        $enable = (bool) $this->getRequest()->getParam('enable');

        try {
            return $this->success($this->moduleToggle->toggle($module, $enable));
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
