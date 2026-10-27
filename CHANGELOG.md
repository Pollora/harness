# Changelog

## [Unreleased]

## [0.1.9] - 2026-10-27

### Changed

- Rector is pinned to `~2.8.1`. Every Rector minor adds rules to the prepared sets, so a floating version turned the Rector gate red on repositories that had not changed (2.8.1: `ExplicitNullableObjectInstanceRector`). A Rector upgrade is now a harness release, with the new rules reviewed once

## [0.1.8] - 2026-10-27

### Changed

- The Rector preset runs on `src` only by default. On tests it emptied fixture methods, turned the closures a test is about into arrow functions and rewrote strings

## [0.1.7] - 2026-10-27

### Changed

- The Rector preset skips the rules that contradict Pint (`!$x` against `! $x`, double quotes) and those that change the public API of classes applications extend (readonly classes, narrower return types, removed parameters, `never`, static methods made non-static), and those that look equivalent and are not: hook callbacks as first-class callables (`has_action()` and `remove_action()` no longer find them), `get_debug_type()` on a class name, removed casts on values WordPress or a driver returns, so a modernisation never breaks an override or a caller

## [0.1.6] - 2026-10-27

### Changed

- The dependency analysis preset also ignores unknown functions (WordPress functions are never autoloadable) and the shadow use of `ext-ctype`, `ext-filter`, `ext-json` and `ext-mbstring`

## [0.1.5] - 2026-10-27

### Changed

- The Dependency analysis gate uses a preset (`presets/composer-dependency-analyser.php`) in a repository that has no `composer-dependency-analyser.php`: WordPress classes (`WP_*`, `wpdb`…) and the unknown classes, functions and shadow dependencies of the tests are no longer reported. A repository with its own file keeps it

## [0.1.4] - 2026-10-27

### Fixed

- `pollora-qa init` run again on a repository keeps its `observed` gates and its `protected` paths: it rewrote `.harness.json` without them, so a repository in enforce mode saw its observed gates block

### Changed

- The Code style gate (and the agent's post-edit fix) uses the repository's own `pint.json` when it requires `declare_strict_types`, instead of always applying the harness preset: a package keeps the style it chose, ARCH-09 still holds, and the gate no longer fails on formatting the preset disagrees with (spacing after `!`, class element order)

## [0.1.3] - 2026-10-26

### Added

- `observed` in `.harness.json`: gates that are reported but never block, in `enforce` mode too. A repository can enforce the gates it passes (static analysis, tests and architecture, audit, public surface, ratchet) while the others (style, Rector, dependency analysis) are still being settled

### Changed

- The README follows the Pollora presentation (banner, badges, "Part of Pollora" note, contributing and license footer); the license holder is RuBee group, as in every Pollora repository

## [0.1.2] - 2026-10-26

### Fixed

- `pollora-qa init` includes the Laravel preset (Larastan) only where `larastan/larastan` is required: Larastan boots the application and made PHPStan fail on integration packages that do not require it
- `pollora-qa init` removes the Larastan and WordPress-stubs extensions from an existing `phpstan.neon` when it adds the presets that include them: PHPStan refuses a file included twice

## [0.1.1] - 2026-10-26

### Fixed

- The `contract` job warns instead of failing when `roave/backward-compatibility-check` cannot reflect a class whose parent no autoloader maps (`WP_CLI_Command`, `Cron_Event_Command`): the API is then not compared
- The workflow written by `pollora-qa init` no longer passes `profile` and `downstream` inputs, which the reusable workflow does not declare: GitHub refused to start it (`startup_failure`). The profile and the downstream repositories come from `.harness.json`

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
