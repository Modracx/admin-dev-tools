<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Flags;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\DevFlags;

class Index extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::flags';

    public function __construct(
        Context $context,
        BlockFactory $blockFactory,
        JsonFactory $jsonFactory,
        private readonly DevFlags $devFlags
    ) {
        parent::__construct($context, $blockFactory, $jsonFactory);
    }

    public function execute()
    {
        try {
            return $this->panel('Modracx_AdminDevTools::panel/flags.phtml', [
                'flags'     => $this->devFlags->getFlags(),
                'warnings'  => $this->devFlags->getWarnings(),
                'mode'      => $this->devFlags->getMode(),
                'can_write' => $this->_authorization->isAllowed('Modracx_AdminDevTools::flags_write'),
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
