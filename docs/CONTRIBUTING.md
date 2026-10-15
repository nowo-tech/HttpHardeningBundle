# Contributing

English docs and PHPDoc only. Before opening a PR or tagging:

1. `make setup-hooks` (REQ-GIT-001 — strips Cursor co-author trailers)
2. `make qa` / `make release-check`
3. Keep `docs/SECURITY.md` aligned with behaviour changes

Report security issues privately per [.github/SECURITY.md](../.github/SECURITY.md).

## AI co-author trailers

Do **not** add AI co-author trailers to commit messages: neither `Co-authored-by: Cursor` / `cursoragent@cursor.com` nor `Co-Authored-By: Claude …` / `noreply@anthropic.com` (REQ-GIT-001). Run `make setup-hooks` so the `commit-msg` hook strips them.
