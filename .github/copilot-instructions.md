# Copilot / coding agent instructions — Outbound URL Guard

## Product

- Symfony bundle that blocks SSRF on outbound `http`/`https` URLs (`nowo-tech/outbound-url-guard-bundle`).
- Core: `OutboundUrlGuard`, `PrivateNetworkTarget`, optional DNS pin via `HostnameDnsLookup`.
- Cloud metadata stays blocked even when `allow_private` is true. Callers still set timeouts, TLS, and `max_redirects: 0`.

## Conventions

- Keep PHPDoc, comments, and Markdown in **English**.
- Do not log raw URLs or DNS answers (they may contain secrets).
- Document config/API changes in `docs/` and `docs/CHANGELOG.md`.
- Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers to commit messages.

## Quality

- Run `make test`, `make phpstan`, and `make cs-check` before release.
- New blocking I/O needs an explicit timeout and a test (REQ-RUNTIME-001).
- Keep PHP line coverage at or above 99% (prefer 100%).
