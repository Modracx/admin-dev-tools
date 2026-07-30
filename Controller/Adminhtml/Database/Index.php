<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Database;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\DatabaseSize;

class Index extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::database';

    public function __construct(
        Context $context,
        BlockFactory $blockFactory,
        JsonFactory $jsonFactory,
        private readonly DatabaseSize $databaseSize
    ) {
        parent::__construct($context, $blockFactory, $jsonFactory);
    }

    public function execute()
    {
        try {
            return $this->panel('Modracx_AdminDevTools::panel/database.phtml', [
                'summary' => $this->databaseSize->getSummary(),
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
