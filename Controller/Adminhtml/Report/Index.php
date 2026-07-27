<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Report;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\ReportList;

class Index extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::reports';

    public function __construct(
        Context $context,
        BlockFactory $blockFactory,
        JsonFactory $jsonFactory,
        private readonly ReportList $reportList
    ) {
        parent::__construct($context, $blockFactory, $jsonFactory);
    }

    public function execute()
    {
        try {
            return $this->panel('Modracx_AdminDevTools::panel/reports.phtml', [
                'reports'   => $this->reportList->getReports(),
                'available' => $this->reportList->isAvailable(),
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
