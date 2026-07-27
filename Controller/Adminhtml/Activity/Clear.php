<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Activity;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\ActivityFeed;

/**
 * Empties the activity log. Its own permission, separate from reading it — being able
 * to read an audit trail should not imply being able to erase it.
 */
class Clear extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::activity_clear';

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
        try {
            $removed = $this->activityFeed->clear();
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }

        return $this->success(
            (string) __('Removed %1 entries. The clear itself has been logged.', $removed)
        );
    }
}
