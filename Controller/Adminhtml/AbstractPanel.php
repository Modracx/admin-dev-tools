<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Block\Adminhtml\Panel;

/**
 * Base for the controllers that render a devbar dropdown panel.
 *
 * All of them answer with {success, html} or {success, message}, which is what
 * window.modracxDevTools.load() in devbar.phtml expects.
 */
abstract class AbstractPanel extends Action implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly BlockFactory $blockFactory,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Render a panel template and return it as JSON.
     *
     * Most panels are plain data rendered by the generic Panel block. The cache and index
     * panels pass their own block class instead, so their existing ACL-aware accessors
     * keep working unchanged.
     */
    protected function panel(string $template, array $data = [], string $blockClass = Panel::class): Json
    {
        $block = $this->blockFactory->createBlock($blockClass, ['data' => $data]);
        $block->setTemplate($template);

        return $this->jsonFactory->create()->setData(['success' => true, 'html' => $block->toHtml()]);
    }

    protected function success(string $message): Json
    {
        return $this->jsonFactory->create()->setData(['success' => true, 'message' => $message]);
    }

    protected function error(string $message): Json
    {
        return $this->jsonFactory->create()->setData(['success' => false, 'message' => $message]);
    }
}
