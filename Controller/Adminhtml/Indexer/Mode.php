<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Indexer;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Modracx\AdminDevTools\Model\ActivityLogger;
use Modracx\AdminDevTools\Model\IndexerInsight;

/**
 * Flip one indexer between "Update on Save" and "Update by Schedule".
 *
 * Its own permission: running an index is a slow no-op at worst, but moving a store to
 * schedule mode when nothing consumes the changelog quietly freezes the storefront.
 */
class Mode extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::reindex_mode';

    public function __construct(
        Context $context,
        private readonly IndexerInsight $indexerInsight,
        private readonly ActivityLogger $activityLogger,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result    = $this->jsonFactory->create();
        $indexerId = (string) $this->getRequest()->getParam('indexer_id');
        $scheduled = (bool) $this->getRequest()->getParam('scheduled');

        if ($indexerId === '') {
            return $result->setData(['success' => false, 'message' => (string) __('Missing indexer_id.')]);
        }

        try {
            $message = $this->indexerInsight->setMode($indexerId, $scheduled);

            $this->activityLogger->logAction('update', 'indexer_state', $indexerId, [
                'mode' => [$scheduled ? 'realtime' : 'schedule', $scheduled ? 'schedule' : 'realtime'],
            ]);

            return $result->setData(['success' => true, 'message' => $message]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
