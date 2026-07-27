<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Indexer;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Indexer\IndexerRegistry;

class Run extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::reindex';

    public function __construct(
        Context $context,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result    = $this->jsonFactory->create();
        $indexerId = (string) $this->getRequest()->getParam('indexer_id');

        if ($indexerId === '') {
            return $result->setData(['success' => false, 'message' => (string) __('Missing indexer_id.')]);
        }

        try {
            $indexer = $this->indexerRegistry->get($indexerId);
            $indexer->reindexAll();
            return $result->setData([
                'success' => true,
                'message' => (string) __('Indexer "%1" reindexed successfully.', $indexerId),
            ]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
