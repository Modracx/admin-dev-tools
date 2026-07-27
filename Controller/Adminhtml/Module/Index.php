<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Module;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\ModuleStatus;
use Modracx\AdminDevTools\Model\ModuleToggle;

class Index extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::modules';

    public function __construct(
        Context $context,
        BlockFactory $blockFactory,
        JsonFactory $jsonFactory,
        private readonly ModuleStatus $moduleStatus,
        private readonly ModuleToggle $moduleToggle
    ) {
        parent::__construct($context, $blockFactory, $jsonFactory);
    }

    public function execute()
    {
        try {
            $status = $this->moduleStatus->getModules();
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }

        $modules = array_map(
            fn (array $module): array => $module + ['protected' => $this->moduleToggle->isProtected($module['name'])],
            $status['modules']
        );

        return $this->panel('Modracx_AdminDevTools::panel/modules.phtml', [
            'modules'     => $modules,
            'summary'     => $status['summary'],
            'can_toggle'  => $this->_authorization->isAllowed('Modracx_AdminDevTools::modules_toggle')
                && $this->moduleToggle->isAvailable(),
            'toggle_note' => $this->moduleToggle->getUnavailableReason(),
        ]);
    }
}
