# HttpHardeningBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behavior lives in `specs/001-baseline/spec.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md`, and integrator docs (`USAGE.md`, `CONFIGURATION.md`, `SECURITY.md`).

### II. Spec-first, test-proven
PHPUnit, PHPStan, and Igor are the mechanical proof. Behavioral changes require tests.

### III. 100% code inventory traceability
Every production file under `src/` must appear in `specs/001-baseline/code-inventory.md`. New files require spec updates in the same PR.

### IV. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`). Skills live in `.cursor/skills/speckit-*`.

### V. Safe defaults
Security headers on by default; anonymous session strip off until public routes are listed. Never overwrite stronger headers already set by the host or edge.

### VI. Symfony compatibility
Follow declared PHP/Symfony ranges in `composer.json` and README badges.

## Governance
Amendments update this file, baseline spec when principles affect behavior, and `CHANGELOG.md` when consumer-visible.

**Version**: 1.0.1 | **Ratified**: 2026-10-07 | **Last Amended**: 2026-10-07
