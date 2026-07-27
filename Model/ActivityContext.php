<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\User\Api\Data\UserInterface;
use Magento\User\Model\UserFactory;

/**
 * Answers "who is doing this, and through what" for a single request.
 *
 * The admin session covers the admin UI. REST and GraphQL have no admin session — there
 * the acting identity comes from UserContextInterface, which is what the token was
 * issued against, so an integration is reported as an integration rather than as
 * whichever admin happens to be logged in elsewhere.
 *
 * Resolved once per request and cached, since a single save can fire several events.
 */
class ActivityContext
{
    public const SOURCE_ADMIN   = 'admin';
    public const SOURCE_REST    = 'rest';
    public const SOURCE_SOAP    = 'soap';
    public const SOURCE_GRAPHQL = 'graphql';
    public const SOURCE_CRON    = 'cron';
    public const SOURCE_CLI     = 'cli';
    public const SOURCE_UNKNOWN = 'unknown';

    /** @var array<string, mixed>|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly State $appState,
        private readonly RequestInterface $request,
        private readonly AuthSession $authSession,
        private readonly UserContextInterface $userContext,
        private readonly UserFactory $userFactory,
        private readonly RemoteAddress $remoteAddress
    ) {
    }

    /**
     * @return array{actor_type: string, actor_id: ?int, actor_name: ?string, source: string,
     *               request_method: ?string, endpoint: ?string, ip: ?string}
     */
    public function get(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $source = $this->resolveSource();

        $this->resolved = array_merge(
            ['source' => $source],
            $this->resolveActor($source),
            $this->resolveRequest($source)
        );

        return $this->resolved;
    }

    private function resolveSource(): string
    {
        try {
            $area = $this->appState->getAreaCode();
        } catch (\Exception $e) {
            return self::SOURCE_UNKNOWN;
        }

        return match ($area) {
            Area::AREA_ADMINHTML   => self::SOURCE_ADMIN,
            Area::AREA_WEBAPI_REST => self::SOURCE_REST,
            Area::AREA_WEBAPI_SOAP => self::SOURCE_SOAP,
            Area::AREA_GRAPHQL     => self::SOURCE_GRAPHQL,
            Area::AREA_CRONTAB     => self::SOURCE_CRON,
            Area::AREA_GLOBAL      => self::SOURCE_CLI,
            default                => self::SOURCE_UNKNOWN,
        };
    }

    /**
     * @return array{actor_type: string, actor_id: ?int, actor_name: ?string}
     */
    private function resolveActor(string $source): array
    {
        if ($source === self::SOURCE_ADMIN) {
            $user = $this->authSession->getUser();
            if ($user && $user->getId()) {
                return [
                    'actor_type' => 'admin',
                    'actor_id'   => (int) $user->getId(),
                    'actor_name' => (string) $user->getUserName(),
                ];
            }
        }

        $userId = $this->userContext->getUserId();
        $type   = $this->userContext->getUserType();

        if ($userId !== null && $type !== null) {
            return match ((int) $type) {
                UserContextInterface::USER_TYPE_ADMIN => [
                    'actor_type' => 'admin',
                    'actor_id'   => (int) $userId,
                    'actor_name' => $this->adminName((int) $userId),
                ],
                UserContextInterface::USER_TYPE_INTEGRATION => [
                    'actor_type' => 'integration',
                    'actor_id'   => (int) $userId,
                    'actor_name' => null,
                ],
                UserContextInterface::USER_TYPE_CUSTOMER => [
                    'actor_type' => 'customer',
                    'actor_id'   => (int) $userId,
                    'actor_name' => null,
                ],
                default => ['actor_type' => 'system', 'actor_id' => null, 'actor_name' => null],
            };
        }

        return ['actor_type' => 'system', 'actor_id' => null, 'actor_name' => null];
    }

    private function adminName(int $userId): ?string
    {
        try {
            /** @var UserInterface $user */
            $user = $this->userFactory->create()->load($userId);

            return $user->getId() ? (string) $user->getUserName() : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @return array{request_method: ?string, endpoint: ?string, ip: ?string}
     */
    private function resolveRequest(string $source): array
    {
        $method   = null;
        $endpoint = null;
        $ip       = null;

        try {
            if (method_exists($this->request, 'getMethod')) {
                $method = (string) $this->request->getMethod();
            }

            if ($source === self::SOURCE_ADMIN && method_exists($this->request, 'getFullActionName')) {
                $endpoint = (string) $this->request->getFullActionName();
            } elseif (method_exists($this->request, 'getPathInfo')) {
                // For REST this is the actual route that was called, e.g. /V1/products/24-MB01
                $endpoint = (string) $this->request->getPathInfo();
            }

            $ip = $this->remoteAddress->getRemoteAddress() ?: null;
        } catch (\Exception $e) {
            // A CLI or cron run has no meaningful request; leave the columns null.
        }

        // Outside a real dispatch getFullActionName() returns just its separators ("__").
        if ($endpoint !== null && trim($endpoint, '_/ ') === '') {
            $endpoint = null;
        }

        return [
            'request_method' => $method !== '' ? $method : null,
            'endpoint'       => $endpoint !== '' ? substr((string) $endpoint, 0, 255) : null,
            'ip'             => $ip !== null ? substr((string) $ip, 0, 45) : null,
        ];
    }
}
