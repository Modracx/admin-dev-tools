<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Environment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\Environment;

class Index extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::environment';

    public function __construct(
        Context $context,
        BlockFactory $blockFactory,
        JsonFactory $jsonFactory,
        private readonly Environment $environment
    ) {
        parent::__construct($context, $blockFactory, $jsonFactory);
    }

    public function execute()
    {
        try {
            return $this->panel('Modracx_AdminDevTools::panel/environment.phtml', [
                'sections' => $this->environment->getSections(),
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
