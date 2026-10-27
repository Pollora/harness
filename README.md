<p align="center">
  <a href="https://pollora.dev">
    <img src="https://raw.githubusercontent.com/Pollora/.github/main/brand/banners/harness.png" width="100%" alt="Pollora Harness: one set of quality gates for every Pollora repository">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/pollora/harness"><img src="https://img.shields.io/packagist/v/pollora/harness" alt="Latest version"></a>
  <a href="https://packagist.org/packages/pollora/harness"><img src="https://img.shields.io/packagist/dt/pollora/harness" alt="Total downloads"></a>
  <a href="https://github.com/Pollora/harness/actions/workflows/ci.yml"><img src="https://github.com/Pollora/harness/actions/workflows/ci.yml/badge.svg" alt="Tests"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Pollora/harness" alt="License"></a>
</p>

One set of rules, checked by machines, for every Pollora repository: code quality, tests, security, performance and the public contract. The same gates run on a developer's machine, in a coding agent's hooks and in CI, so a change that breaks something is stopped before it is merged.

> Part of [Pollora](https://pollora.dev), the Laravel framework for WordPress. It is a development tool: install it with `--dev`, in a Pollora package, a plugin or a project. Nothing of it ships to the sites that run your code.

The harness follows five principles:

1. **A rule exists only if a machine checks it.** Each rule has an identifier (`ARCH-02`, `SEC-03`…) quoted in every failure.
2. **A ratchet, not a big bang.** Every metric starts from where the repository is today and may only improve.
3. **One harness, configured once.** Repositories declare what they are (a profile); the harness decides how they are checked.
4. **The public contract is sacred.** Attributes, hooks fired, configuration keys and commands only break in a major version.
5. **Developer simplicity stays the compass.** The harness constrains the framework's code, never how a Pollora project is written.

## Installation

```bash
composer require --dev pollora/harness
vendor/bin/pollora-qa init --profile=core
composer qa
```

`init` never overwrites a file. It writes `.harness.json`, adds the harness preset to `phpstan.neon`, and creates what is missing: `tests/Arch.php`, `rector.php`, `.github/workflows/harness.yml`, `.claude/settings.json`, `AGENTS.md`, and the `qa` and `qa:fast` Composer scripts. Review the diff, pin the workflow call to a release tag (`php-package.yml@v0.1.2`; `init` writes `@main`), commit, and the repository is in **observe mode**: every gate runs and reports, none blocks. Switch `.harness.json` to `"mode": "enforce"` once the reports are clean.

## Profiles

| Profile | For | Notable rules |
| --- | --- | --- |
| `core` | Standalone packages (`hook`, `discovery`, `media`…) | Laravel-free (ARCH-04), hexagon, downstream run of the framework |
| `framework` | `pollora/framework` | Hexagon, Larastan, no third-party plugin in the core (ARCH-05) |
| `integration` | Packages built on the framework (`metabox-bridge`, `debugbar`, `nectar`) | Larastan |
| `wp-plugin` | WordPress plugins distributed on their own | PHPStan max target |
| `template` | Themes, plugin and module templates, the skeleton | Checked from the outside by `template.yml`: nothing to install, see [Templates](#templates) |
| `project` | Client projects built on Pollora | The security rules apply to the project's code |

## Observe, enforce, and gates left observed

`.harness.json` sets the `mode`. In `observe` every gate runs and reports, none blocks. In `enforce` a failed gate fails the check. A repository that is ready for most gates but not all lists the others in `observed`: they are reported in the job summary and never block, in `enforce` mode too.

```json
{
    "profile": "core",
    "mode": "enforce",
    "observed": ["Code style", "Rector", "Dependency analysis"]
}
```

The names are the ones `pollora-qa check` prints: `Code style`, `Static analysis`, `Rector`, `Tests and architecture`, `Dependency policy`, `Dependency analysis`, `Security patterns`, `Dependency audit`, `Public surface`, `Ratchet`. Shorten the list as each gate comes clean.

The **Code style** gate checks with the repository's own `pint.json` when that file already requires `declare_strict_types` (ARCH-09), so a package keeps the style it chose; without one, or with one that does not require it, the harness preset applies.

The **Dependency analysis** gate uses the repository's `composer-dependency-analyser.php` when there is one; without it, a harness preset ignores what is noise in a WordPress repository (WordPress classes and functions, which are not Composer packages, the usual PHP extensions, and the classes and libraries of the tests); a repository that needs more writes its own file and can start from the preset with `require`.

## Commands

| Command | Does |
| --- | --- |
| `pollora-qa check` | Every gate (G1 and G2): style, PHPStan, Rector, tests and architecture, dependency policy and analysis, Semgrep, `composer audit`, ratchet |
| `pollora-qa check --fast` | The fast gates (G0) on the files changed in the working tree |
| `pollora-qa ratchet [--update]` | Compares the metrics with `.harness.json`; `--update` records improvements, never regressions |
| `pollora-qa deps` | Checks the declared dependencies against the profile |
| `pollora-qa surface [--update]` | Compares the public surface with `api-surface.json`; `--update` records it |
| `pollora-qa rules [ID]` | Lists the rules, or explains one |
| `pollora-qa agents` | Regenerates the harness block of `AGENTS.md` |
| `pollora-qa hook <event>` | Agent hooks: `pre-edit`, `post-edit`, `stop` |

`check` writes `.harness/report.json` and, in GitHub Actions, a table in the job summary.

## What is in the box

- **Presets** in `presets/`: Pint, PHPStan (`base.neon`, plus `laravel.neon` and the opt-in `strict.neon`), Semgrep rules, and the Rector preset class `Pollora\Harness\Rector\Preset`.
- **ARCH-02 as a PHPStan rule**: code in a `Domain` namespace calls no global WordPress function (`pollora.noWordPressCallInDomain`).
- **Architecture tests** registered by `tests/Arch.php` from the layers found under the PSR-4 roots (`Domain`, `Application`, `Infrastructure`/`Adapter`, `UI`).
- **A second reusable workflow**, `.github/workflows/template.yml`, for templates: see [Templates](#templates).
- **A reusable workflow**, `.github/workflows/php-package.yml`: quality gates, a test matrix (PHP × lowest/stable dependencies), a gitleaks scan, and the **downstream** job that runs the framework's tests against a changed core package.
- **Agent hooks** for Claude Code: style fixed after each edit, PHPStan on the edited file, protected threshold files (QA-02), the fast gates before the agent hands back.

## Templates

A template (the skeleton, a theme, a plugin or a module) is copied into every project built from it, so it cannot carry the harness: `pollora/harness` in its `require-dev` would be installed by every `composer create-project`, and a `.harness.json` or an `AGENTS.md` at its root would land in every project. `template.yml` checks it from the outside instead. The template adds one file, `.github/workflows/harness.yml`, which the scaffolders strip:

```yaml
name: Harness

on:
  push:
    branches: [main]
  pull_request:
  workflow_dispatch:

permissions: {}

jobs:
  harness:
    uses: Pollora/harness/.github/workflows/template.yml@v0.1.10
    with:
      mode: enforce # observe reports and never blocks
    permissions:
      contents: read
```

It runs the gates that need nothing but the source: the secrets in the history (SEC-08), the code style (Pint, the template's own `pint.json` or the Laravel preset), the security patterns (SEC-01, SEC-03, SEC-04, SEC-05, CONV-10, with the rules of the version the workflow is pinned at) and the dependency audit of a committed `composer.lock` (SEC-09). PHPStan, Rector, the dependency analysis, the tests matrix, the API contract and the downstream run need a package: a template keeps its own tests in its own workflow.

## Public surface

Projects built on Pollora rely on more than PHP signatures: the attributes and their parameters, the hooks Pollora fires, the configuration keys and the console commands. `pollora-qa surface --update` records them in `api-surface.json`, one line per entry:

```json
"attribute:Pollora\\Attributes\\WpRestRoute($permissionCallback)": "Pollora\\Attributes\\WpRestRoute\\Permission|string|null, optional = NULL",
"hook:pollora/media/stored": "hook",
"config:media.disk": "key",
"command:pollora:make:block": "command"
```

In CI, a pull request that changes the surface needs a label (QA-04): `api-change` when it only adds entries (a minor version), `breaking` when it removes or changes one, or adds a required parameter to an existing attribute (a major version, after a deprecation). The diff shows exactly what a release changes for its users. PHP signatures are compared with the latest tag by `roave/backward-compatibility-check` (QA-05).

## Rules

`vendor/bin/pollora-qa rules` prints the catalogue, from `resources/rules.php`. Each rule is `enforced` (fails the gate), `observed` (reported only) or `planned` (not checked by this version yet).

An exception to a rule is written on the line it concerns, with its reason: `// @phpstan-ignore <identifier> (reason)` or `// nosemgrep -- reason`. Nothing is silenced without a trace.

## Status

This is phase 1 of the rollout: the harness measures every repository without blocking it. Integration tests against a real WordPress, adapter contract tests, mutation testing and performance budgets follow in the next phases.

## Contributing

Contributions are welcome: see the [contributing guide](https://github.com/Pollora/.github/blob/main/CONTRIBUTING.md). Report security issues privately, as described in the [security policy](https://github.com/Pollora/.github/blob/main/SECURITY.md).

## License

Pollora Harness is open-source software licensed under the [MIT license](LICENSE). © [RuBee group](https://rubee.group)
