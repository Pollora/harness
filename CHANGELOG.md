# Changelog

## [Unreleased]

## [0.1.0] - 2026-10-26

### Added

- `pollora-qa surface`: records the public surface (attributes and their parameters, hooks fired, configuration keys, commands) in `api-surface.json` and compares the code with it; a change needs the `api-change` or `breaking` label (QA-04)
- The public surface gate in `pollora-qa check`, reading the pull request's labels in CI
- The `contract` job in the reusable workflow: `roave/backward-compatibility-check` against the latest tag (QA-05)
- CONV-04 and CONV-06 reported from the surface: hook and command names that do not follow the conventions
- `pollora-qa` command line: `init`, `check` (full and `--fast`), `ratchet`, `deps`, `rules`, `agents`, `hook`.
- Profiles `core`, `framework`, `integration`, `wp-plugin`, `template` and `project`, with observe and enforce modes.
- Rule catalogue (`resources/rules.php`) with ARCH, CONV, SEC, PERF and QA identifiers.
- Presets for Pint, PHPStan, Rector and Semgrep.
- PHPStan rule `pollora.noWordPressCallInDomain` (ARCH-02).
- Pest architecture rules registered from the discovered hexagonal layers (ARCH-01, ARCH-03, ARCH-08, ARCH-09, CONV-03, CONV-05, CONV-07).
- Ratchet on the PHPStan level and baseline, line coverage, type coverage and mutation score (QA-01).
- Reusable GitHub workflow with quality gates, test matrix, secret scan and the downstream framework run (QA-03).
- Claude Code hooks protecting the threshold files (QA-02) and running the fast gates.
