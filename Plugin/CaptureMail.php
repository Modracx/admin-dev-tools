<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Plugin;

use Magento\Framework\Mail\TransportInterface;
use Modracx\AdminDevTools\Model\MailCatcher;

/**
 * Records every outgoing mail as it is handed to the transport, and — outside production,
 * and only when explicitly switched on — stops it there.
 *
 * Wrapped around rather than after the send so a failed delivery is recorded too: the
 * message that never arrived is the one worth reading.
 */
class CaptureMail
{
    public function __construct(private readonly MailCatcher $mailCatcher)
    {
    }

    /**
     * @param callable $proceed
     * @throws \Exception
     */
    public function aroundSendMessage(TransportInterface $subject, callable $proceed)
    {
        $message = $this->message($subject);

        if ($message !== null && $this->suppress()) {
            $this->mailCatcher->record($message, false);

            return null;
        }

        try {
            $result = $proceed();
        } catch (\Exception $e) {
            if ($message !== null) {
                $this->mailCatcher->record($message, false, $e->getMessage());
            }

            throw $e;
        }

        if ($message !== null) {
            $this->mailCatcher->record($message, true);
        }

        return $result;
    }

    /**
     * Deciding not to send is the one call here that can change whether a customer gets
     * their order confirmation. If that decision cannot be made cleanly, send.
     */
    private function suppress(): bool
    {
        try {
            return $this->mailCatcher->shouldSuppress();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * getMessage() is only on the interface since 101.0.0 and some third-party transports
     * still do not implement it — a mail with no readable message is not worth an error.
     */
    private function message(TransportInterface $subject): ?\Magento\Framework\Mail\MessageInterface
    {
        try {
            $message = $subject->getMessage();
        } catch (\Throwable $e) {
            return null;
        }

        return $message instanceof \Magento\Framework\Mail\MessageInterface ? $message : null;
    }
}
