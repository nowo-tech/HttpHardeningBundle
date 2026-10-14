# Feature specification — worker-safe profiler CSP handler and bounded request body

**Status**: Implemented — **v1.5.0**  
**Last audited:** 2026-10-14  
**Extends:** [002-csp-public-cache](../002-csp-public-cache/spec.md), [004-no-store-paths](../004-no-store-paths/spec.md)

## Summary

Two helpers hosts kept copying:

1. **Profiler CSP handler in worker mode.** WebProfilerBundle's `web_profiler.csp.handler`
   (`ContentSecurityPolicyHandler`) keeps a `cspDisabled` flag. `ProfilerController` sets it on
   `/_wdt/styles` and `/_profiler` pages and nothing resets it. The service is shared, so the
   toolbar listener (`WebDebugToolbarListener`) uses the same instance. In a long-running worker
   (FrankenPHP worker mode, kernel not reset between requests), once a worker has served one
   profiler request it strips `Content-Security-Policy` from every later page and renders the
   toolbar's inline `<script>` without a nonce. Hosts redefined the service with `shared: false`
   in `when@dev`. The bundle now does it with a compiler pass.
2. **Bounded request body.** `Request::getContent()` buffers the whole body before any size check;
   a chunked upload without `Content-Length` can fill worker memory. Hosts had a static helper that
   rejects a declared oversize `Content-Length` and otherwise streams `php://input` up to cap + 1.

## Goals

- `web_profiler.isolate_csp_handler` (default `true`): `web_profiler.csp.handler` becomes non-shared when it exists; no-op without WebProfilerBundle (prod)
- `Nowo\HttpHardeningBundle\Http\BoundedRequestBody::read(Request, int $maxBytes): string` throwing `RequestBodyTooLargeException` (an `HttpException` with status 413) and `tryRead()` returning `null`

## Non-goals

- Resetting the profiler handler via `kernel.reset` (the class is `@internal` and has no reset method)
- A request listener that enforces a global body cap (endpoints have different limits; the edge owns the global one)
- Multipart / form parsing (PHP buffers those before the kernel runs)

## Functional requirements

| ID | Requirement |
| -- | ----------- |
| FR-WP-001 | `web_profiler.isolate_csp_handler` boolean, default `true`; the extension stores it in the build-time parameter `.nowo_http_hardening.web_profiler.isolate_csp_handler` (not dumped) |
| FR-WP-002 | `WebProfilerCspHandlerPass` (registered by `NowoHttpHardeningBundle::build()`, before optimisation) calls `setShared(false)` on the `web_profiler.csp.handler` definition when it exists and the switch is on |
| FR-WP-003 | Without the definition (no WebProfilerBundle, prod) or with the switch off the container is unchanged |
| FR-BODY-001 | `BoundedRequestBody::read()` treats a cap below 1 as 1 |
| FR-BODY-002 | A `Content-Length` above the cap throws `RequestBodyTooLargeException` (`isDeclared() === true`) before reading the body |
| FR-BODY-003 | Otherwise at most cap + 1 bytes are read from `Request::getContent(true)`; more than cap bytes throws `RequestBodyTooLargeException` (`isDeclared() === false`). This covers chunked bodies without `Content-Length` and understated lengths |
| FR-BODY-004 | `RequestBodyTooLargeException` extends `HttpException` with status 413 and exposes `getMaxBytes()` |
| FR-BODY-005 | `BoundedRequestBody::tryRead()` returns `null` instead of throwing |

## Acceptance

- `tests/Unit/DependencyInjection/Compiler/WebProfilerCspHandlerPassTest` (non-shared by default, switch off, no WebProfilerBundle, compiled container gives each consumer its own instance)
- `tests/Unit/NowoHttpHardeningBundleTest` (pass registered), `ConfigurationTest` / `NowoHttpHardeningExtensionTest` (default and switch)
- `tests/Unit/Http/BoundedRequestBodyTest` (within cap, declared oversize read nothing, chunked oversize stops at cap + 1, chunked within cap, understated length, cap below 1)

## Reference consumers

- `nowo-tech/symfony-beacon` (KIT-EXTRACTION rows #18 and #23): drops the `web_profiler.csp.handler` override in `config/packages/web_profiler.yaml` and `App\Shared\Http\BoundedRequestBody`
- `podologiapriego-web`: drops the `when@dev` `web_profiler.csp.handler` override in `config/services.yaml`
