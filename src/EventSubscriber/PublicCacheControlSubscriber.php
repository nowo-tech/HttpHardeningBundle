<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\EventSubscriber;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;
use function is_string;

/**
 * Cacheable `Cache-Control` for anonymous GET/HEAD on configured public routes.
 *
 * Without it, public pages that never open a session fall back to Symfony's `private, max-age=0`.
 * Runs after the session listener (-1000) and {@see AnonymousSessionCookieStripSubscriber}
 * (-1024) so its header wins (priority -1100).
 *
 * Pages (HTML) get the page policy (default `private, max-age=120, must-revalidate`) plus
 * `Vary: Cookie`; a policy already made `public` upstream (strip subscriber) is normalised to it.
 * Visitors carrying only consent / locale cookies are cached too; a session or remember-me
 * cookie opts out. ETags are removed from pages when a CSP nonce was issued: a 304 would pair
 * the cached body (old nonce) with a new CSP header and block its scripts.
 *
 * Files (sitemap, robots, manifest…) get the file policy and are only cached for cookie-less
 * requests. Signed-in users, responses that set cookies, non-200 responses and `no-store` are
 * left alone.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class PublicCacheControlSubscriber
{
    public const DEFAULT_PAGE_CACHE_CONTROL = 'private, max-age=120, must-revalidate';

    public const DEFAULT_FILE_CACHE_CONTROL = 'public, max-age=3600, must-revalidate';

    /**
     * @param list<string> $pageRouteNamePrefixes
     * @param list<string> $pageRouteNames
     * @param list<string> $fileRouteNames
     */
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private array $pageRouteNamePrefixes = [],
        private array $pageRouteNames = [],
        private string $pageCacheControl = self::DEFAULT_PAGE_CACHE_CONTROL,
        private array $fileRouteNames = [],
        private string $fileCacheControl = self::DEFAULT_FILE_CACHE_CONTROL,
        private string $sessionCookieName = 'PHPSESSID',
        private string $rememberMeCookieName = 'REMEMBERME',
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$request->isMethodCacheable() || Response::HTTP_OK !== $response->getStatusCode()) {
            return;
        }

        $page = $this->classify($request);
        if (null === $page
            || $this->tokenStorage->getToken()?->getUser() instanceof UserInterface
            || $response->headers->hasCacheControlDirective('no-store')
            || [] !== $response->headers->getCookies()) {
            return;
        }

        $cookies = $request->cookies;
        $personal = $page
            ? ($cookies->has($this->sessionCookieName) || ('' !== $this->rememberMeCookieName && $cookies->has($this->rememberMeCookieName)))
            : [] !== $cookies->all();
        // Already made public upstream (anonymous session strip): normalise pages to the page policy.
        if ($personal && !$response->headers->hasCacheControlDirective('public')) {
            return;
        }

        $policy = $page ? $this->pageCacheControl : $this->fileCacheControl;
        if ('' === $policy) {
            return;
        }

        $response->headers->remove('Cache-Control');
        $response->headers->set('Cache-Control', $policy);
        if (!$page) {
            return;
        }

        $response->setVary('Cookie', false);
        if ($request->attributes->has(ContentSecurityPolicySubscriber::REQUEST_ATTRIBUTE_NONCE)) {
            $response->setEtag(null);
        }
    }

    /**
     * @return bool|null true for a page route, false for a file route, null when not configured
     */
    private function classify(Request $request): ?bool
    {
        $route = $request->attributes->get('_route');
        if (!is_string($route)) {
            return null;
        }

        if (in_array($route, $this->fileRouteNames, true)) {
            return false;
        }

        if (in_array($route, $this->pageRouteNames, true)) {
            return true;
        }

        foreach ($this->pageRouteNamePrefixes as $prefix) {
            if ('' !== $prefix && str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return null;
    }
}
