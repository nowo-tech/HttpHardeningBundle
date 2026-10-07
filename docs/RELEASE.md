# Release

Current stable: **v1.0.0**.

1. Update [CHANGELOG.md](CHANGELOG.md) and [UPGRADING.md](UPGRADING.md).
2. Run `make release-check`.
3. Commit release docs on `main` (no Cursor co-author trailers).
4. `git tag -a v1.0.0 -m "Release v1.0.0"` then push branch and tag.
5. Confirm GitHub Release / Packagist for `nowo-tech/http-hardening-bundle`.
