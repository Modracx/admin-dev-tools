<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Indexer\Model\Indexer\CollectionFactory;

class ReindexButtons extends Template
{
    protected $_template = 'Modracx_AdminDevTools::panel/index.phtml';

    public function __construct(
        Context $context,
        private readonly CollectionFactory $indexerCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getIndexers(): array
    {
        $indexers = [];
        foreach ($this->indexerCollectionFactory->create()->getItems() as $indexer) {
            $indexers[] = [
                'id'     => $indexer->getId(),
                'title'  => $indexer->getTitle(),
                'status' => $indexer->getStatus(),
            ];
        }
        return $indexers;
    }

    public function canReindex(): bool
    {
        return $this->_authorization->isAllowed('Modracx_AdminDevTools::reindex');
    }
}
