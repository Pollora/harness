# Changelog

## [Unreleased]

### Added

- `pollora-qa` command line: `init`, `check` (full and `--fast`), `ratchet`, `deps`, `rules`, `agents`, `hook`.
- Profiles `core`, `framework`, `integration`, `wp-plugin`, `template` and `project`, with observe and enforce modes.
- Rule catalogue (`resources/rules.php`) with ARCH, CONV, SEC, PERF and QA identifiers.
- Presets for Pint, PHPStan, Rector and Semgrep.
- PHPStan rule `pollora.noWordPressCallInDomain` (ARCH-02).
- Pest architecture rules registered from the discovered hexagonal layers (ARCH-01, ARCH-03, ARCH-08, ARCH-09, CONV-03, CONV-05, CONV-07).
- Ratchet on the PHPStan level and baseline, line coverage, type coverage and mutation score (QA-01).
- Reusable GitHub workflow with quality gates, test matrix, secret scan and the downstream framework run (QA-03).
- Claude Code hooks protecting the threshold files (QA-02) and running the fast gates.
