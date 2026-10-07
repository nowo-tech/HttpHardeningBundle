# Spec-driven development

## Table of contents

- [Purpose](#purpose)
- [Product layers](#product-layers)
- [User stories](#user-stories)
- [Bundle functional scope](#bundle-functional-scope)
- [Public API (Packagist contract)](#public-api-packagist-contract)
- [Validating the functional spec](#validating-the-functional-spec)
- [Requirement identifiers (Makefile)](#requirement-identifiers-makefile)
- [Contributor workflow](#contributor-workflow)
- [Relationship with Engram](#relationship-with-engram)
- [GitHub Spec Kit](#github-spec-kit)
- [See also](#see-also)

## Purpose

This document describes **what HTTP Hardening guarantees**, how behavior is proven, and how Spec Kit / Engram fit into the maintainer workflow.

## Product layers

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) and operator manual [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Integrator contract** — response subscribers + config alias `nowo_http_hardening`.
3. **Repo `REQ-*` traceability** — Makefile and CI. This package has no demos.

Mechanical proof is **PHPUnit** + **PHPStan** + **Igor**. There is no separate executable spec language.

## User stories

| ID | Intent | Scope / docs |
| -- | ------ | ------------ |
| US-01 | Public HTML gets baseline hardening headers when missing | `SecurityHeadersSubscriber` · [CONFIGURATION](CONFIGURATION.md) |
| US-02 | HSTS is not pinned on loopback HTTPS by default | `hsts_skip_loopback` · [CONFIGURATION](CONFIGURATION.md) |
| US-03 | Anonymous public GET strips the session cookie for CDN/checkers | `AnonymousSessionCookieStripSubscriber` · [USAGE](USAGE.md) |
| US-04 | Authenticated users keep the session cookie | `UserInterface` check · [SECURITY](SECURITY.md) |

## Bundle functional scope

**Goal:** Harden public HTTP responses with safe-by-default headers and an optional anonymous session-cookie strip for cacheable public routes.

**In scope:** set-if-missing security headers, optional HSTS, opt-in session strip by route name/prefix, Flex recipe defaults.

**Explicit non-goals:** CSP generation, WAF, TLS termination, authn/authz, HTML sanitization, admin UI.

**Not part of the Packagist API:** repository tooling (`Makefile`, `.github/`, `.cursor/`).

## Public API (Packagist contract)

| Artifact | Responsibility |
| --- | --- |
| `NowoHttpHardeningBundle` | Bundle entry + DI extension alias |
| `SecurityHeadersSubscriber` | Apply baseline response headers when missing |
| `AnonymousSessionCookieStripSubscriber` | Strip session cookie on configured anonymous public GET/HEAD |
| Config alias `nowo_http_hardening` | `security_headers.*`, `anonymous_session_strip.*` |

## Validating the functional spec

| Command | Proves |
| ------- | ------ |
| `make test` / `make test-coverage` | Unit behavior (PHP statements >= 99%, target 100%) |
| `make phpstan` / `make cs-check` / `make rector-dry` / `make igor` | Static quality / worker audit |
| `make release-check` | Full pre-release gate |

Behavior changes **require** tests under `tests/`.

## Requirement identifiers (Makefile)

| ID | Location | Meaning |
| -- | -------- | ------- |
| REQ-MAKE-001 | root `Makefile` | `ensure-up`, standard targets, `release-check` |
| REQ-MAKE-002 | root `Makefile` `release-check` | Co-author check, open PRs, style, Rector, PHPStan, Igor, coverage |
| REQ-MAKE-006 | `setup-hooks` | Install `.githooks/commit-msg` |
| REQ-MAKE-008 | `update-deps` | `composer update` in the bundle container (no demos) |
| REQ-MAKE-009 | `Makefile` | No path outside this git repository |
| REQ-GIT-001 | `.githooks/`, `.scripts/check-no-cursor-coauthor.sh` | No Cursor co-author trailers |
| REQ-REL-003 | `.scripts/check-open-prs.sh` | No unresolved open pull requests |
| REQ-TEST-003 | coverage gate | Clover statements >= 99% |
| REQ-SEC-001..004 | `docs/SECURITY.md`, `.github/SECURITY.md` | Security docs + AI audit Pass (good) |

## Contributor workflow

1. Change behavior in `src/` with matching tests.
2. Update `specs/001-baseline/spec.md` and `code-inventory.md` when files or FRs change.
3. Update integrator docs (`USAGE`, `CONFIGURATION`, `SECURITY`, `CHANGELOG`, `UPGRADING`) as needed.
4. Run `make release-check` before tagging.

## Relationship with Engram

See [ENGRAM.md](ENGRAM.md). Engram MCP is configured in `.cursor/mcp.json`; it does not replace Spec Kit or PHPUnit.

## GitHub Spec Kit

This repo uses **Cursor Agent** Spec Kit skills under `.cursor/skills/speckit-*`.

- Baseline: [`specs/001-baseline/`](../specs/001-baseline/)
- Full manual: [`SPEC-KIT.md`](SPEC-KIT.md)

When changing production code, update the baseline inventory in the same PR (see SPEC-KIT maintainer checklist).

## See also

- [SPEC-KIT.md](SPEC-KIT.md)
- [specs/001-baseline/spec.md](../specs/001-baseline/spec.md)
- [SECURITY.md](SECURITY.md)
- [CONFIGURATION.md](CONFIGURATION.md)
- [USAGE.md](USAGE.md)
