# Feature specification — CSP nonce-free style paths

**Status**: Implemented — **v1.2.0**  
**Last audited:** 2026-10-11  
**Extends:** [002-csp-public-cache](../002-csp-public-cache/spec.md)

## Summary

Rich-text editors such as CKEditor 5 inject `<style>` elements at runtime. With a nonce in
`style-src-elem`, browsers ignore `'unsafe-inline'`, so those styles are blocked and the editor
renders broken. Hosts that adopted the bundle CSP had to keep their own CSP listener only to drop
the style nonce on editor routes. This feature adds a narrow, opt-in per-path relaxation for
`style-src-elem` only.

## Goals

- One config list `csp.nonce_free_style_path_prefixes` (default `[]`, BC with v1.1.x)
- On a matching path `style-src-elem` gets `'unsafe-inline'` **instead of** the nonce
- `script-src` keeps its nonce everywhere (no script relaxation)
- Segment-aware matching: `/admin/legal` matches `/admin/legal` and `/admin/legal/…`, not `/admin/legalese`

## Non-goals

- General per-path overrides of other directives (use `CspSourceProviderInterface` to add sources)
- Removing the nonce from `script-src`

## Functional requirements

| ID | Requirement |
| -- | ----------- |
| FR-CSP-011 | `csp.nonce_free_style_path_prefixes` (list of path prefixes, default `[]`) is passed to `ContentSecurityPolicySubscriber` |
| FR-CSP-012 | When the request path equals a prefix or starts with `prefix/`, `style-src-elem` omits the nonce and contains `'unsafe-inline'`; empty / `/`-only entries are ignored; a trailing `/` on a prefix is ignored |
| FR-CSP-013 | `script-src` keeps the nonce on those paths; debug relaxations do not duplicate `'unsafe-inline'` |

## Acceptance

- `tests/Unit/EventSubscriber/ContentSecurityPolicySubscriberTest::testNonceFreeStylePathsAllowUnsafeInlineStylesWithoutNonce`
- `tests/Unit/DependencyInjection/ConfigurationTest` (default `[]`) and `NowoHttpHardeningExtensionTest` (argument wiring)

## Reference consumer

`nowo-tech/symfony-beacon` (CKEditor 5 on `/admin/legal`, project / group / role forms, `/dashboard` new-project dialog) replaced its host `ContentSecurityPolicySubscriber` with this bundle.
