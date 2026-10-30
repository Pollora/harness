# Changelog

## [Unreleased]

## [0.1.16] - 2026-10-30

### Changed

- **The *Distribution archive* gate (QA-06) blocks.** It started observed in 0.1.15 so that raising the harness turned no repository red; all the Pollora packages and the framework now run `pollora-qa archive --fix` clean, so a release archive that carries a development file (tests, CI, tool configuration, agent files) fails the check in `enforce` mode. A repository that is not ready lists `Distribution archive` under `observed` in `.harness.json`

## [0.1.15] - 2026-10-30

### Added

- **A *Distribution archive* gate (QA-06)**: `pollora-qa check` builds the archive `git archive` would produce and fails when a development file is in it (`tests/`, `.github/`, `.claude/`, `AGENTS.md`, `.harness.json`, `api-surface.json`, `rector.php`, `composer-dependency-analyser.php`, `phpstan*.neon`, `phpunit*.xml`, `pint.json`, `renovate.json`, `.gitignore`, `.gitattributes`…), with the `.gitattributes` lines to add. It reads the real archive, because an `export-ignore` on a directory cannot be seen file by file. It runs for the `core`, `framework`, `integration` and `wp-plugin` profiles: the archive of a `template` or a `project` is the product. It is skipped where `git archive` cannot be built. **It starts observed** (reported, never blocking, like the npm audit did), so that raising the harness to this version turns no repository red; a repository fixes `.gitattributes` with `pollora-qa archive --fix` when it raises it, and the gate will block in a later version
- **`pollora-qa archive [--fix]`**: checks the same thing from the command line; `--fix` adds the missing `export-ignore` lines to `.gitattributes`, under one header, and never removes a line
- **`pollora-qa init` keeps the files it creates out of the archive** of a `core`, `framework`, `integration` or `wp-plugin` repository: `.claude`, `.harness.json`, `AGENTS.md`, `rector.php`, `api-surface.json` and `composer-dependency-analyser.php`, plus the `tests`, `.github` and tool configuration it finds. `.gitattributes` lists itself, since a tracked `.gitattributes` would otherwise travel in the archive

## [0.1.14] - 2026-10-29

### Fixed

- **A file that calls `exit()` or `die()` no longer ends a run with a pass.** A WordPress plugin starts nearly every file with `defined('ABSPATH') || exit;`. `pollora-qa surface` and the *Public surface* gate load the plugin's classes, so the first guarded file stopped the whole process with status 0, before the summary and the *Ratchet* gate, and CI read that as a pass. The run now fails with a message that says what happened
- **The surface extractor defines `ABSPATH` and `WPINC` when WordPress is absent** (only if they are not defined), so the guarded files of a plugin load and its attributes, hooks, configuration keys and commands are recorded

## [0.1.13] - 2026-10-28

### Changed

- **SEC-04 also skips three more WordPress core functions that return escaped HTML**: `get_block_wrapper_attributes()`, `get_previous_posts_link()` and `get_next_posts_link()`. With them, the Pollora theme template reports nothing for SEC-04, and its `observed: security` can go
- ARCH-05 has no exception any more: `src/ThirdParty/` is no longer excluded from the rule, since the framework moved WooCommerce and WP Rocket to their own packages. A framework that still has code in `src/ThirdParty/` that names a plugin is now reported

## [0.1.12] - 2026-10-28

### Added

- **Dependency audit (npm)** (SEC-09): `pollora-qa check` runs `npm audit --audit-level=high` for every `package-lock.json` of the repository (the root and up to two levels down, never `node_modules`, `vendor` or `.worktrees`), and `template.yml` has a matching `npm-audit` job. Composer's audit does not see these alerts. The gate is **observed**: it reports and never blocks, in `enforce` mode too, until a later release makes it blocking

### Changed

- **SEC-04 no longer reports the outputs that are escaped by construction**: `{!! !!}` followed by an escaping function (`esc_attr`, `esc_html`, `esc_url`, `esc_js`, `esc_textarea`, their `__`, `_e` and `_x` forms, `wp_kses`, `wp_kses_post`, `wp_kses_data`, `absint`, `wc_kses_notice`, `wp_strip_all_tags`), a component `$slot`, or one of the WordPress and WooCommerce functions that return HTML they have escaped themselves (`post_class`, `wc_price`, `wp_nav_menu`, `get_search_form`…, the list is in `presets/semgrep/common.yml`). Only the first call is looked at, so `{!! esc_html($a) . $b !!}` passes: keep the whole expression escaped. Anything else (`apply_filters()`, `do_shortcode()`, `get_option()`, a variable, a theme's own helper) is still reported and needs `{{ }}`, an escaping function, or a `nosemgrep` comment that says why. On the Apiary theme the reports go from 310 to 114

## [0.1.11] - 2026-10-28

### Added

- `observed` input of `template.yml`: the gates (`secrets`, `style`, `security`, `audit`) reported but never blocking, in `enforce` mode too, as `observed` does in a package's `.harness.json`

### Fixed

- The style gate of `template.yml` parses a template: the placeholders the scaffolder fills in (`%theme_namespace%`) are not PHP and made Pint fail on every file that holds one, whatever its style. Pint runs on a copy where each placeholder is an identifier

## [0.1.10] - 2026-10-27

### Added

- `template.yml`, a reusable workflow for a template (the skeleton, a theme, a plugin, a module): secrets, code style, security patterns and dependency audit, run from the outside. The template installs nothing and carries no `.harness.json`: it only adds `.github/workflows/harness.yml`, so nothing of the harness is copied into the projects built from it. `mode: enforce` blocks a merge, `observe` reports

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
