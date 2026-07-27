<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Activity;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\ActivityFeed;

class Index extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::activity';

    public function __construct(
        Context $context,
        BlockFactory $blockFactory,
        JsonFactory $jsonFactory,
        private readonly ActivityFeed $activityFeed
    ) {
        parent::__construct($context, $blockFactory, $jsonFactory);
    }

    public function execute()
    {
        $filters = [
            'source' => (string) $this->getRequest()->getParam('source', ''),
            'action' => (string) $this->getRequest()->getParam('event', ''),
            'q'      => (string) $this->getRequest()->getParam('q', ''),
        ];

        try {
            return $this->panel('Modracx_AdminDevTools::panel/activity.phtml', [
                'available' => $this->activityFeed->isAvailable(),
                'entries'   => $this->activityFeed->getEntries($filters),
                'sources'   => $this->activityFeed->getSources(),
                'total'     => $this->activityFeed->getTotal(),
                'filters'   => $filters,
                'retention' => ActivityFeed::RETENTION_DAYS,
                'can_clear' => $this->_authorization->isAllowed('Modracx_AdminDevTools::activity_clear'),
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
