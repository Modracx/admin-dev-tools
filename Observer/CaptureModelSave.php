<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Model\AbstractModel;
use Modracx\AdminDevTools\Model\ActivityLogger;

/**
 * Capture the pre-save state of a model.
 *
 * Bound only in the adminhtml, REST, SOAP and GraphQL areas — see the events.xml in
 * each of those etc/ folders — so storefront traffic never reaches it.
 */
class CaptureModelSave implements ObserverInterface
{
    public function __construct(private readonly ActivityLogger $activityLogger)
    {
    }

    public function execute(Observer $observer): void
    {
        $object = $observer->getEvent()->getObject();

        if ($object instanceof AbstractModel) {
            $this->activityLogger->captureBeforeSave($object);
        }
    }
}
