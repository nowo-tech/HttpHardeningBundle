# Release

Current stable: **v1.7.0**.

1. Update [CHANGELOG.md](CHANGELOG.md) and [UPGRADING.md](UPGRADING.md).
2. Confirm [SECURITY.md](SECURITY.md) checklist 12.4.1 and REQ-SEC-004 grade.
3. Run `make release-check`.
4. Commit release docs on `main` (no Cursor or Claude co-author trailers).
5. `git tag -a vX.Y.Z -m "Release vX.Y.Z"`, then run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001: the release commit is not covered by the earlier `release-check`), then push branch and tag.
6. Confirm GitHub Release / Packagist for `nowo-tech/http-hardening-bundle`.
