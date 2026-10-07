# Feature Specification: HTTP Hardening baseline

**Status**: Implemented — `v1.0.0`  
**Input**: Extract host security headers and anonymous session-cookie strip into a reusable nowo-tech Symfony bundle.

## User Scenarios

### US1 — Public HTML carries hardening headers

Anonymous GET to a public page receives nosniff, frame options, referrer policy, permissions policy, CORP, and HSTS on non-loopback HTTPS when not already set by the edge.

### US2 — Anonymous public GET is CDN-friendly

Configured public routes strip the session cookie for guests and set public Cache-Control; authenticated users keep the session.

## Requirements

- FR-001 Configurable security headers with skip-if-present semantics
- FR-002 HSTS skips loopback by default
- FR-003 Optional anonymous session strip by route name prefix/list
- FR-004 Strip preserves non-session cookies
