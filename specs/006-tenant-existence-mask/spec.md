# Feature specification — tenant existence mask

**Status**: Implemented — **v1.7.0**  
**Last audited:** 2026-10-16  
**Extends:** [004-no-store-paths](../004-no-store-paths/spec.md)

## Summary

Multi-tenant apps put tenant ids in URLs (`/projects/{uuid}/…`). When a signed-in user with no
access gets **403** on an existing tenant and **404** on an unknown one, the status code is an
existence oracle: tenants can be enumerated. Hosts kept a `kernel.exception` listener that turns
the denial into 404 for users with no access at all. This feature moves it into an opt-in
`tenant_existence_mask` section with a host SPI for the access decision.

## Goals

- `tenant_existence_mask.enabled` (default `false`), `path_patterns` (PCRE list with a tenant capture), `access_checker` (service id), `priority` (default 16)
- SPI `Nowo\HttpHardeningBundle\Security\TenantAccessCheckerInterface::hasAnyAccess(UserInterface $user, string $tenantId): bool`
- Signed-in user + no access at all → 404 identical to an unknown tenant; member with a too-low role → 403 kept; anonymous → firewall entry point untouched

## Non-goals

- Entity-level masking inside a tenant (owner-scoped lookups stay a host concern, documented)
- Several checkers per pattern (one checker dispatches on the id if a host has several tenant kinds)
- Masking API error formats other than the kernel exception flow

## Functional requirements

| ID | Requirement |
| -- | ----------- |
| FR-TM-001 | Section off by default; `TenantExistenceMaskSubscriber` is registered only when `enabled: true`, once, via the extension `kernel.event_listener` tag on `kernel.exception` (method `onException`) |
| FR-TM-002 | Only `Symfony\Component\Security\Core\Exception\AccessDeniedException` and `AccessDeniedHttpException` are considered; other throwables are untouched |
| FR-TM-003 | The tenant id is the `tenant` named group, else group 1, of the first pattern matching `Request::getPathInfo()`; no match or an empty capture → untouched |
| FR-TM-004 | No token, a non-`UserInterface` user, or no `security.token_storage` → untouched (anonymous keeps the login redirect / 401) |
| FR-TM-005 | `hasAnyAccess()` `false` → throwable replaced by `new NotFoundHttpException()` with **no previous** and no message; `true` → untouched (403) |
| FR-TM-006 | Default priority 16, configurable, minimum 2 (always before the firewall `ExceptionListener` at 1) |
| FR-TM-007 | Each pattern is compiled during config processing and must contain a capture group; `enabled: true` without patterns is rejected (`InvalidConfigurationException`) |
| FR-TM-008 | `access_checker: null` references the service id `TenantAccessCheckerInterface::class` (autowiring alias); a string references that service id |

## Acceptance

- `tests/Unit/EventSubscriber/TenantExistenceMaskSubscriberTest` (both exception types, no previous, member keeps 403, anonymous, no token storage, non-matching paths, other exceptions, named / numbered / empty captures)
- `tests/Unit/DependencyInjection/TenantExistenceMaskConfigurationTest` (defaults, validation, capture-group detection, wiring, custom checker and priority)
- `tests/Functional/TenantExistenceMaskTest` (FrameworkBundle + SecurityBundle kernel with the real firewall: stranger 404 like an unknown id, member 403, other tenant 404, anonymous 401, non-matching path 403)

## Reference consumer

`nowo-tech/symfony-beacon` replaces `App\Project\EventSubscriber\ProjectExistenceMaskSubscriber` with `tenant_existence_mask` and a `TenantAccessCheckerInterface` implementation backed by `ProjectAccessService::resolveAccess()`.
