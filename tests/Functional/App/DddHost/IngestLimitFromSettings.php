<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Functional\App\DddHost;

use Nowo\HttpHardeningBundle\Model\HttpRequestInfo;
use Nowo\HttpHardeningBundle\RateLimit\RuleLimitProviderInterface;

/**
 * DDD host adapter: per-project runtime quota keyed by the `project` route parameter (framework-free snapshot).
 */
final readonly class IngestLimitFromSettings implements RuleLimitProviderInterface
{
    /**
     * @param array<string, int> $quotas project => requests per minute
     */
    public function __construct(
        private array $quotas,
    ) {
    }

    public function limitPerMinute(string $rule, HttpRequestInfo $request): int
    {
        $project = $request->routeParams['project'] ?? null;
        if (null === $project) {
            // kernel.request before the router: fall back to the path.
            $project = explode('/', trim($request->routerPath, '/'))[1] ?? '';
        }

        return $this->quotas[(string) $project] ?? 0;
    }
}
