# Feature specification — no-store paths

**Status**: Implemented — **v1.4.0**  
**Last audited:** 2026-10-13  
**Extends:** [002-csp-public-cache](../002-csp-public-cache/spec.md)

## Summary

Screens that read or write credentials (mailer DSN, API keys, webhook tokens, config exports)
must never land in a browser or proxy cache. Symfony's session listener only sends
`private, must-revalidate`, which still lets the browser store the page (back button, disk
cache) and keeps `ETag` / `Last-Modified` for conditional revalidation. Hosts kept their own
response listener for this. This feature adds an opt-in `no_store` section.

## Goals

- `no_store.enabled` (default `false`), `no_store.path_patterns` (PCRE list, matched on `Request::getPathInfo()`), `no_store.routes` (exact `_route` names)
- On a matching main request: `Cache-Control: no-store, private`; `ETag` and `Last-Modified` removed
- `no-store` wins over every other bundle cache policy, in any order
- Invalid patterns fail at container build with a clear config error

## Non-goals

- Route-name prefixes or attribute-based opt-in (`#[Cache]` already covers per-controller cases)
- `Pragma` / `Expires` legacy headers

## Functional requirements

| ID | Requirement |
| -- | ----------- |
| FR-NS-001 | `no_store` section, off by default; `NoStoreSubscriber` is registered only when `enabled: true`, once, via the extension `kernel.event_listener` tag (no attribute, no autoconfigure) |
| FR-NS-002 | A main request matches when its `_route` is in `routes` or `getPathInfo()` matches any `path_patterns` entry; sub-requests are ignored |
| FR-NS-003 | On a match `Cache-Control` is replaced with `no-store, private` and `ETag` / `Last-Modified` are removed |
| FR-NS-004 | Priority -1200 on `kernel.response`: after the session listener (-1000), `anonymous_session_strip` (-1024) and `public_cache` (-1100). If no-store ran first, `PublicCacheControlSubscriber` already backs off on `no-store` |
| FR-NS-005 | Each `path_patterns` entry is compiled during config processing; an invalid or empty pattern raises `InvalidConfigurationException` naming `no_store.path_patterns` |

## Acceptance

- `tests/Unit/EventSubscriber/NoStoreSubscriberTest` (path match / non-match, route match, sub-request, header removal, session-listener policy, strip + public cache in both orders)
- `tests/Unit/DependencyInjection/ConfigurationTest` (defaults, invalid patterns) and `NowoHttpHardeningExtensionTest` (off by default, wiring, priority, registered exactly once, invalid pattern)

## Reference consumer

`nowo-tech/symfony-beacon` (spec `137`, REQ-INSTANCE-006) replaces `App\Shared\Http\SensitivePageNoStoreSubscriber` with `no_store.path_patterns`.
