<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Api\BookmarkRepositoryInterface;

/**
 * Admin grid bookmarks (the ui_bookmark rows behind saved grid views, column layout
 * and filters) for the *current* admin user.
 *
 * Scoping to the signed-in user is deliberate and enforced here rather than in the
 * controller: resetting someone else's grid state from a toolbar would be surprising,
 * and there is no legitimate reason for this tool to reach across users.
 */
class BookmarkTool
{
    public function __construct(
        private readonly BookmarkRepositoryInterface $bookmarkRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly AuthSession $authSession
    ) {
    }

    /**
     * Namespaces (grids) the current user has saved state for.
     *
     * @return array<int, array{namespace: string, views: int, updated_at: ?string}>
     */
    public function getNamespaces(): array
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return [];
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('user_id', $userId)
            ->create();

        $namespaces = [];
        foreach ($this->bookmarkRepository->getList($criteria)->getItems() as $bookmark) {
            $namespace = (string) $bookmark->getNamespace();

            if (!isset($namespaces[$namespace])) {
                $namespaces[$namespace] = ['namespace' => $namespace, 'views' => 0, 'updated_at' => null];
            }

            $namespaces[$namespace]['views']++;

            $updatedAt = $bookmark->getUpdatedAt();
            if ($updatedAt !== null
                && ($namespaces[$namespace]['updated_at'] === null
                    || $updatedAt > $namespaces[$namespace]['updated_at'])
            ) {
                $namespaces[$namespace]['updated_at'] = (string) $updatedAt;
            }
        }

        ksort($namespaces);

        return array_values($namespaces);
    }

    /**
     * Delete every bookmark the current user has for one namespace, returning the count.
     *
     * @throws LocalizedException
     */
    public function reset(string $namespace): int
    {
        $namespace = trim($namespace);

        if ($namespace === '' || !preg_match('~^[a-z0-9_.]+$~i', $namespace)) {
            throw new LocalizedException(__('Invalid grid namespace.'));
        }

        $userId = $this->getUserId();
        if ($userId === null) {
            throw new LocalizedException(__('No admin user in session.'));
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('user_id', $userId)
            ->addFilter('namespace', $namespace)
            ->create();

        $deleted = 0;
        foreach ($this->bookmarkRepository->getList($criteria)->getItems() as $bookmark) {
            $this->bookmarkRepository->delete($bookmark);
            $deleted++;
        }

        if ($deleted === 0) {
            throw new LocalizedException(__('No saved state found for "%1".', $namespace));
        }

        return $deleted;
    }

    private function getUserId(): ?int
    {
        $user = $this->authSession->getUser();

        return $user && $user->getId() ? (int) $user->getId() : null;
    }
}
