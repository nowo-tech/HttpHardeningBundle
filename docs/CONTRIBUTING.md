# Contributing

English docs and PHPDoc only. Before opening a PR or tagging:

1. `make setup-hooks` (REQ-GIT-001 — strips Cursor co-author trailers)
2. `make qa` / `make release-check`
3. Keep `docs/SECURITY.md` aligned with behaviour changes

Report security issues privately per [.github/SECURITY.md](../.github/SECURITY.md).

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Cursor Agent

Contributors using Cursor Agent on this repository should enable these user-level settings (REQ-IDE-006):

1. **Submit with Ctrl + Enter** (macOS: **⌘ + Enter**) — Cursor Settings → Agents / Chat. Enter inserts a newline; Ctrl/⌘+Enter submits.
2. **Queue follow-ups** in user `settings.json` (Linux `~/.config/Cursor/User/settings.json`, Windows `%APPDATA%\Cursor\User\settings.json`, macOS `~/Library/Application Support/Cursor/User/settings.json`):

```json
{
  "cursor.composer.queueMessageDefaultBehavior": "queue"
}
```

Do not use `"steer"` as the default: it interrupts a running agent turn.

## AI co-author trailers

Do **not** add AI co-author trailers to commit messages: neither `Co-authored-by: Cursor` / `cursoragent@cursor.com` nor `Co-Authored-By: Claude …` / `noreply@anthropic.com` (REQ-GIT-001). Run `make setup-hooks` so the `commit-msg` hook strips them.

Maintainers run `make check-no-cursor-coauthor` **after** creating a release commit and **before** `git push` (REQ-GIT-001): an earlier `release-check` run does not cover the release commit itself.
