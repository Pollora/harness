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

`init` never overwrites a file. It writes `.harness.json`, adds the harness preset to `phpstan.neon`, and creates what is missing: `tests/Arch.php`, `rector.php`, `.github/workflows/harness.yml`, `.claude/settings.json`, `AGENTS.md`, and the `qa` and `qa:fast` Composer scripts. Review the diff, review the workflow call (`init` pins it to the release tag of the installed harness, such as `php-package.yml@v0.1.17`, and to `@main` only when the harness runs from a branch), commit, and the repository is in **observe mode**: every gate runs and reports, none blocks. Switch `.harness.json` to `"mode": "enforce"` once the reports are clean.

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

The names are the ones `pollora-qa check` prints: `Code style`, `Static analysis`, `Rector`, `Tests and architecture`, `Dependency policy`, `Dependency analysis`, `Security patterns`, `Security patterns (observed)`, `Dependency audit`, `Public surface`, `Performance budgets`, `Ratchet`. Shorten the list as each gate comes clean.

The **Code style** gate checks with the repository's own `pint.json` when that file already requires `declare_strict_types` (ARCH-09), so a package keeps the style it chose; without one, or with one that does not require it, the harness preset applies.

### Commands that keep their name (CONV-06)

A package whose console commands are named for the tool itself (`new`, `self-update`, `nectar:mcp`) cannot take the `pollora:` prefix without breaking its public API. List them under `allow_commands` in `.harness.json`, one entry per command (`command`) or per family of names (`prefix`), each with the reason it keeps its name:

```json
{
    "allow_commands": [
        { "command": "new", "reason": "the installer is named for the tool, like laravel new" },
        { "prefix": "nectar:", "reason": "nectar:* is the name of the MCP server" }
    ]
}
```

`reason` is required, and an entry gives either `command` (the exact name) or `prefix` (the start of names), not both; anything else makes `.harness.json` invalid. The *Public surface* gate then prints these commands as `keeps its name (allow_commands: …)` instead of as a problem, so the exemption stays visible, and any other command that does not start with `pollora:` is still reported. `.harness.json` is a protected file (QA-02): a person adds the entry, an agent does not. `init` and `ratchet --update` keep it.

**An exemption of an observed rule is declared, not applied.** CONV-02, CONV-08 and CONV-12 (and CONV-09, which is now enforced) may be listed under `allow_rules` before they become blocking, so that the repository that cannot satisfy them yet says so, with its reason, on the day they are promoted. While the catalogue lists the rule as `observed` the exemption changes nothing: the rule keeps running and reporting (an exemption would hide findings for a rule that blocks nothing), the gate says `declared, not applied while the rule is observed: CONV-08 — <reason> (allow_rules)` and does not count it in `exempted`, and the exemption is still counted by `exempted_rules`, so its ceiling is recorded and can only go down. The day the rule is `enforced`, the same line skips it with `exempted: CONV-08 — <reason>`.

### Services the tests need (`services`)

A repository whose tests talk to a real service lists it in `.harness.json`:

```json
{
    "services": ["redis"]
}
```

The reusable workflow then starts the service in the *Quality gates* and *Tests* jobs (a Redis 7 on `127.0.0.1:6379`) and installs the PHP extensions it needs (`redis` and `igbinary`), so the repository's tests run against it instead of skipping. The tests read the address from `REDIS_HOST` and `REDIS_PORT`, and should fail rather than skip when the `CI` variable is set, which GitHub Actions sets. Only `redis` is known; any other name makes `.harness.json` invalid.

### Where the time goes (`slow_step_share`)

`check` times every gate. The console summary shows the time of each one and a total, the job summary (`$GITHUB_STEP_SUMMARY`) has a *Time* column and a total line, and `.harness/report.json` has `total_seconds` and the `seconds` of each step (the number of steps does not change). To be warned when one gate takes most of the run, give the share of the total above which a gate is flagged:

```json
{
    "slow_step_share": 0.5
}
```

A gate that takes more than that share of the total, and at least 30 seconds, is listed as `Slow step` in the console and in the job summary, and under `slow_steps` in the JSON report. It is a warning, never a failure. Without the key nothing is flagged; a value that is not above 0 and up to 1 makes `.harness.json` invalid.

### Architecture rules a repository cannot satisfy yet (`allow_rules`)

A repository can hold a rule it cannot satisfy without a breaking change: `Pollora/attributes` cannot make its published attribute classes final and readonly (ARCH-08). Instead of skipping the test by hand from `tests/Arch.php`, which also suspends the checks the rule still does well, list the rule under `allow_rules` in `.harness.json`, with the reason:

```json
{
    "allow_rules": [
        { "rule": "ARCH-08", "reason": "the attribute classes are published; making them final is a breaking change" }
    ]
}
```

`tests/Arch.php` keeps its one `Architecture::for(dirname(__DIR__))->register()` line. The tests of the exempted rule are registered as skipped, with `exempted: ARCH-08 — <reason>` as the reason Pest prints, and the *Architecture* and *Tests and architecture* gates say `exempted: ARCH-08 — <reason> (allow_rules)` before they run; every other rule runs as usual, so a new violation of it still fails. The exemption covers the whole rule, not one class or namespace.

The summary of `pollora-qa check` (console, job summary, `.harness/report.json`) does not read such a gate as a plain pass: it prints `✔ passed  Tests and architecture … (1 rule exempted: ARCH-08)`, and the JSON step carries `"exempted": ["ARCH-08"]`. The number of exemptions is also a metric of the ratchet, `exempted_rules` (see below): adding an exemption, or having more than the recorded ceiling, fails QA-01 in enforce mode; removing one lowers the ceiling with `ratchet --update`.

`rule` must be a rule that `tests/Arch.php` evaluates (ARCH-01, ARCH-03, ARCH-08, ARCH-09, CONV-02, CONV-03, CONV-05, CONV-07, CONV-08, CONV-09, CONV-12) and `reason` is required: an unknown rule, a rule checked by another gate, a rule listed twice or an entry without a reason makes `.harness.json` invalid, with a message that names the entry. `.harness.json` is a protected file (QA-02): a person adds the entry, an agent does not. `init` and `ratchet --update` keep it.

**An exemption of an observed rule is declared, not applied.** CONV-02, CONV-08 and CONV-12 (and CONV-09, which is now enforced) may be listed under `allow_rules` before they become blocking, so that the repository that cannot satisfy them yet says so, with its reason, on the day they are promoted. While the catalogue lists the rule as `observed` the exemption changes nothing: the rule keeps running and reporting (an exemption would hide findings for a rule that blocks nothing), the gate says `declared, not applied while the rule is observed: CONV-08 — <reason> (allow_rules)` and does not count it in `exempted`, and the exemption is still counted by `exempted_rules`, so its ceiling is recorded and can only go down. The day the rule is `enforced`, the same line skips it with `exempted: CONV-08 — <reason>`.

### An npm advisory with no fix yet (`npm_audit`)

Both dependency audits call a service of the network (the Packagist advisory API, the npm registry) and tolerate its incidents without ever hiding a finding. A failure of the transport (timeout, DNS, refused or reset connection, HTTP 5xx or 429) is attempted again: 3 attempts in all, 5 s then 15 s apart (20 s at most). A vulnerability is a result and fails at once, without a second attempt; so does any other error. A service still unreachable after the attempts fails the step, with `advisory API unreachable after 3 attempts` and the last error, so that a network incident reads differently from a vulnerability and is never a pass. `pollora-qa composer-audit [--locked]` runs the same code for the reusable workflow of the templates.

The *Dependency audit (npm)* gate runs `npm audit --json` over every `package-lock.json` (the root and the directories two levels down, without `node_modules`, `vendor` or `.worktrees`) and blocks on every high or critical advisory. An advisory can have no patched version: `GHSA-fc5q-39xc-vjpf` (ReDoS in `picomatch`, vulnerable up to 4.0.7, no `first_patched_version`) turns every project that uses Vite red, and downgrading Vite to avoid it would trade a theoretical ReDoS for known fixed flaws. Instead of turning the gate off, list the advisory under `npm_audit.allow` in `.harness.json`, with a reason and an expiry:

```json
{
    "npm_audit": {
        "allow": [
            { "advisory": "GHSA-fc5q-39xc-vjpf", "reason": "ReDoS in picomatch <= 4.0.7, no patched version published; build-time only", "expires": "2026-12-18" }
        ]
    }
}
```

- An advisory is tolerated only when its identifier is listed **and** the date is not past (the exception holds through the day it names). Every other high or critical advisory still blocks. Because npm reports a package that only depends on the vulnerable one (`vite`, `tinyglobby`, `fdir`) through that advisory, one entry covers the whole dependency chain.
- An exception that has expired fails the gate and says so: `exception expired on 2026-12-18`. Nothing is tolerated silently for good.
- The tolerated advisories are printed with their reason and date by `check`, and written to the job summary of GitHub Actions (`GITHUB_STEP_SUMMARY`); an exception that matches no advisory any more is reported, so it can be removed.
- `advisory` must be a GitHub identifier (`GHSA-xxxx-xxxx-xxxx`), `reason` is required, and `expires` is a calendar date `YYYY-MM-DD` at most 90 days from today: anything else, or a repeated advisory, makes `.harness.json` invalid, with a message that names the entry.
- If `npm audit` cannot reach the registry or prints no report, the gate fails: a missing report is never read as a clean one.
- The gate is observed by default (`OBSERVED_BY_DEFAULT`), like before; the exceptions change what it reports, not whether it blocks.

A template (the skeleton, a theme) needs no `.harness.json`. Its reusable workflow runs `pollora-qa npm-audit`, the same code as the `check` step, which reads the `npm_audit` key from `.harness.json` or, failing that, from `.github/harness.json` (a directory the release archives leave out), when the template carries one (see [A .harness.json for a template](#a-harnessjson-for-a-template)). `--config=FILE` names another file. `.harness.json` is a protected file (QA-02): a person adds the entry, an agent does not.

The **Dependency analysis** gate uses the repository's `composer-dependency-analyser.php` when there is one; without it, a harness preset ignores what is noise in a WordPress repository (WordPress classes and functions, which are not Composer packages, the usual PHP extensions, and the classes and libraries of the tests); a repository that needs more writes its own file and can start from the preset with `require`.

## Commands

| Command | Does |
| --- | --- |
| `pollora-qa check` | Every gate (G1 and G2): style, PHPStan, Rector, tests and architecture, dependency policy and analysis, Semgrep, `composer audit`, `npm audit` (observed, with the time-limited exceptions of `npm_audit`), the public surface (and the names of its hooks and commands), the performance budgets and nightly measures, ratchet |
| `pollora-qa check --fast` | The fast gates (G0) on the files changed in the working tree; the whole tree where git is not usable |
| `pollora-qa check --strict` | Also fails when a gate could not run because a tool is missing (Semgrep, a `vendor/bin/` tool) |
| `pollora-qa check --coverage[=FILE]` | Runs the tests with coverage and compares the Clover report with the ratchet (default `.harness/coverage.xml`) |
| `pollora-qa check --mutation` | Also runs Pest's mutation testing (`pest --mutate --everything --covered-only`) and compares its score with the ratchet's `msi`; reported, never blocking by itself |
| `pollora-qa check --mutation-canary[=FILES]` | Runs no gate: only checks that a mutation score could be believed (mutants load, tests pass on unchanged code run as a mutant) |
| `pollora-qa check --mutation --mutation-base=REF` | With a limited `mutation.scope` in `.harness.json`, the commit the changed files are counted from (and, for `changed-relative`, the base each file is measured on) (default `HEAD^1`) |
| `pollora-qa promote-report [--json] [<path>... \| --root=<dir> [--only=a,b]]` | For each observed rule, what would block here if it became enforced (read only, see [Promoting a rule](#promoting-a-rule)); with paths or `--root`, one rule x repository matrix |
| `pollora-qa check --mutation --mutation-scope=all\|changed\|added\|changed-relative` | What `--mutation` mutates in this run, over the `mutation.scope` of `.harness.json`. A limited scope is held to `mutation.min`, or to 50 % when the file sets none; an unknown value fails before any gate runs |
| `pollora-qa ratchet [--update]` | Compares the metrics with `.harness.json`; `--update` records improvements, never regressions |
| `pollora-qa deps` | Checks the declared dependencies against the profile |
| `pollora-qa surface [--update]` | Compares the public surface with `api-surface.json`; `--update` records it |
| `pollora-qa archive [--fix]` | Checks that development files stay out of the release archive (QA-06); `--fix` writes the missing `export-ignore` lines |
| `pollora-qa composer-audit [--locked]` | The Composer dependency audit alone (SEC-09), which tries again when Packagist cannot be reached; same code as the `check` step, used by the reusable workflow of the templates |
| `pollora-qa check --template [--config=FILE]` | The ratchet of a template's optional `.harness.json`, what the `Ratchet (QA-01)` job of `template.yml` runs; nothing without a file |
| `pollora-qa npm-audit [--config=FILE]` | The npm dependency audit alone (SEC-09), for a template that cannot carry the harness; same code as the `check` step |
| `pollora-qa rules [ID]` | Lists the rules, or explains one |
| `pollora-qa agents` | Regenerates the harness block of `AGENTS.md` |
| `pollora-qa hook <event>` | Agent hooks: `pre-edit`, `post-edit`, `stop` |

`check` writes `.harness/report.json` and, in GitHub Actions, a table in the job summary.

### Missing tools

A gate whose tool is not installed does not run, and is not a pass: the summary marks it `– no tool`, prints the command that installs the tool (`pipx install semgrep`, `composer require --dev laravel/pint`…), and ends with "Every gate that ran passed, but not every gate ran." instead of "Every gate passed."; the job summary and `report.json` say the same (`missing_tool`, `install`). Locally this is how Semgrep, which guards SEC-01 to SEC-07 and CONV-10, goes unnoticed; CI installs it. `check --strict` makes the missing tool fail the check, in `observe` mode too; a gate listed under `observed` is left out.

### Without git

PHPStan analyses any file it is given, whatever `paths` says. So `check --fast` and the `post-edit` hook give it only the changed files its configuration covers (`paths` and `excludePaths`, includes taken into account, as `phpstan dump-parameters` reports them); when none is left, *Static analysis* is skipped and says why. When that cannot be determined (the command fails, or no `paths` are configured), every changed file is analysed, as before.

`check --fast` takes the changed files from git. Where git cannot answer (a worktree created on the host and opened from DDEV, whose `.git` points outside the container; no git binary; a directory git does not trust), it says so and checks the whole tree: Pint without `--dirty`, PHPStan on every configured path. It is slower, and may report a file you did not touch, but the style gate no longer fails for a reason that is not style.

### Exempting some classes only (`classes`)

An exemption of a whole rule is too coarse for a rule that judges classes one by one: exempting CONV-08 because a few classes are extension points would also stop judging the hundreds that are not. CONV-02, CONV-08 and CONV-09, which read the classes from the files, take an optional `classes` list that limits the exemption to some of them:

```json
{
    "allow_rules": [
        {
            "rule": "CONV-08",
            "reason": "Colt models are extended by projects",
            "classes": ["Pollora\\Colt\\Model\\*", "Pollora\\Colt\\Database"]
        }
    ]
}
```

A pattern is a fully qualified class name (`Acme\Foo`) or a namespace prefix ending in `\*` (`Acme\Models\*`, every class below that namespace at any depth): nothing else, no regular expression and no bare `*` (leave `classes` out for the whole rule). The rule keeps judging every other class; the exempted ones are removed from its problems and named, by a skipped test and by the *Architecture* and *Tests and architecture* gates: `exempted: CONV-08 — <reason> — 3 classes: A, B, C`. While the rule is `observed` the exemption is declared and not applied (`declared, not applied while the rule is observed: …`), like a whole-rule exemption. Several entries may name the same rule, each with its own reason (the first that covers a class takes it); an entry for a rule can not be mixed with a whole-rule entry for that rule. `ARCH-08` and the rules judged by Pest's engine take no `classes`: the first loads the classes by reflection, which a repository without its dependencies cannot do, the others have no per-class list.

**A pattern that exempts nothing is reported, never silent.** A pattern that matches no class of the repository (the class was renamed or removed) is `stale exemption: <rule> — "<pattern>" matches no class of the repository (remove it).`, and one that matches classes the rule does not report any more (they became `final`) says it matches no class that the rule reports. Both are warnings for now: the test is `incomplete`, the gate prints the line, nothing fails. The attribution of a problem to a class is read from its line (`Acme\Foo is not final…`, `Acme\Foo::bar() is logic of its own…`, or the file of a CONV-02 line).

`pollora-qa promote-report` takes the exemptions into account: `CONV-08  would block (15 found, 12 exempted by allow_rules in 4 classes: 3 remaining)`, `clean` when nothing remains, with the stale patterns listed; the JSON rows gain `found`, `exempted_problems`, `exempted_classes` and `stale` (`count` is what remains).

### Exempted rules in the ratchet

`exempted_rules` counts the architecture rules `allow_rules` exempts, and lower is better, like `phpstan_baseline`. It is only measured where it matters: a repository without `allow_rules` and without the key in `ratchet` shows it as `unmeasured` and nothing is recorded, so an existing `.harness.json` keeps passing as it is. In a repository that uses `allow_rules`, the first `pollora-qa ratchet --update` records the current count; from then on a count above the recorded one is `regressed` (QA-01 fails in enforce mode), and `ratchet --update` lowers the ceiling when an exemption is removed, down to `0`, never raises it. To exempt one more rule on purpose, a person raises the number by hand in `.harness.json`, a protected file (QA-02). `allow_commands` is not tracked by the ratchet.

`exempted_classes` counts the per-class exemptions: the number of (rule, class) pairs that `classes` entries remove from a rule, a class counting once per rule and only when the rule reports it. So a `final` class under an exempted namespace costs nothing, a new open class there raises the figure and fails QA-01 against the recorded ceiling (a namespace prefix cannot widen unnoticed), and a class made `final` lowers it. Lower is better, like `exempted_rules`; `exempted_rules` counts only the whole-rule entries. It is measured only where it matters: without a `classes` entry and without the key in `ratchet`, it reads `unmeasured`, so an existing `.harness.json` is unchanged. The first `ratchet --update` after adding a `classes` entry records it; a declared exemption of an observed rule counts too.

### Coverage and the ratchet

`ratchet` and `check` compare the line coverage with `.harness.json` when they find a Clover report: the one given by `--coverage=FILE`, otherwise `.harness/coverage.xml` (what `check --coverage` writes), `.harness/clover.xml` or `build/logs/clover.xml`, and they print which one they read. Without a report, a recorded coverage reads `no report` and a note says where the harness looked and how to produce one; nothing is compared, and the ratchet does not fail for it. Produce a report with `vendor/bin/pollora-qa check --coverage` (or `vendor/bin/pest --coverage-clover .harness/coverage.xml`), and refresh it before trusting the comparison: an old file is read as it is.

### Mutation score and the ratchet

Line coverage says which lines the tests run, not whether they would notice a change. `check --mutation` runs `pest --mutate --everything --covered-only` (Pest 3.8 and later, with `pestphp/pest-plugin-mutate`; the step is skipped without it), reads the `Score:` line it prints and gives it to the ratchet as `msi`, in percent, higher is better. No `covers()` declaration is needed, and code no test reaches is left to the coverage gate.

The run is parallel. A suite that fails that way (a global function a helper declares, a test that depends on the order) prints no score, so the step repeats it once in a single process; when the tests themselves fail, there is no score and the step says so. The *Mutation testing* gate is observed by default: it reports the score and never fails a build. The ratchet fails only a repository that recorded an `msi` and let it drop. Record the first value with `pollora-qa ratchet --update --metric=msi:79.17`, using the score `check --mutation` printed.

**In CI.** The reusable workflow reads `.harness.json`: a repository that recorded `ratchet.msi` gets `check --mutation` in its *Quality gates* job, and one that did not is untouched. So adopting the gate is one commit: run `check --mutation` once, record the score, push. A mutation run takes from seconds to ten minutes or more, depending on the number of mutants.

**Record a floor below the score you measured.** The figure moves from a run to the next: a mutant that loops until Pest's timeout counts as killed or not depending on the load of the machine (89.18 % and 90.53 % on the same code), and the parallel and single-process runs do not give exactly the same count. Record about two points under what you measured, and raise it by hand when the score has stayed higher for a while (`ratchet --update` only raises, never lowers).

**A mutation run that cannot write its files.** Pest writes each mutant under `vendor/pestphp/pest-plugin-mutate/.temp/mutations`. If that directory is missing or read-only, every mutant process fails and counts as killed, so the score reads 100 % whatever the tests assert. The gates read Pest's `Failed to open stream` warning for that path and fail, naming the directory, rather than report the score. A fresh `composer install` has the directory; a CI that restores `vendor/` from a cache, or a container that deletes it, may not. The warning is only printed when PHP displays errors, so the run passes `-d display_errors=1`, and does not rely on it: before Pest starts, the harness writes a file in that directory and checks that the mutants already there can be read and rewritten (Pest is not started otherwise); once Pest has stopped it checks again, and also refuses a mutant left empty (a full disk).

**No cache.** Pest keeps the mutants it generated in `.temp/pest-mutate-cache` and does not check that their files exist: after a run that could not write them, the next one reads 100 % with no warning. Every run of the harness passes `--no-cache`, so mutants are generated again each time and the cache of a developer is neither read nor written. A `pest --mutate` typed by hand still uses it: `--clear-cache` empties it.

#### A score needs proof that mutants load, and that the tests pass on unchanged code

Pest hands a mutant to its process through `PEST_MUTATION_TESTING` and `PEST_MUTATION_FILE` and serves it through a stream wrapper on `file`. If something else serves the original (Patchwork, which Brain Monkey loads, or a bootstrap), mutants survive unseen or their processes die and count as killed. After every run that has a score, in every scope, the harness therefore starts Pest as one of those processes with a "mutant" that only records that it was executed, and stops it. No record, no score: the gate fails, saying why (on the base of `changed-relative`, `base unmeasured`). A real 100 % passes, because what is judged is the record, not the value.

- **With files** (`changed`, `added`, `changed-relative`), each file is probed with the real suite. A file included without its mutant refuses the score. A file no test includes has no covered line, hence no mutant, and says nothing; but at least one file of the set must have run its mutant (the suite is tried once more with `--parallel` before concluding that none did).
- **Whole repository**, there is no single file: the harness writes `src/PolloraCanary<random>.php` and a test next to the others that loads Patchwork (when installed) and requires it, runs that test and removes both.
**The tests must also pass on code that did not change.** A mutant's process that fails is a caught mutant for Pest, one that loops is a timeout, counted as caught too: a suite that breaks there whatever the file contains scores mutants nothing tested. So, before Pest runs:

1. **The suite runs alone** (`pest`, then `pest --parallel` if that fails, with coverage first, then without). It gives the duration and, with a coverage driver (pcov, Xdebug), which tests cover which file. When it does not pass, there is nothing to compare with.
2. **The tests run as a mutant of an unchanged file**: `PEST_MUTATION_TESTING` is the source file, `PEST_MUTATION_FILE` an exact copy of it. As Pest does, only the tests that cover the file are selected, by name (`--filter`, in several runs when too long for PCRE; for the whole repository, the first source file stands for the others, with every test that covers a source file), and `--parallel` is never passed: the variables of a ParaTest worker are set instead. With pest-plugin-mutate 3, whose mutants collect coverage, so does this run. Without coverage data every test runs, which can refuse a score for a test no mutant of the file would run.
3. **They must exit 0** (a run that fails is tried once more) within five times the duration of step 1, and never less than 120 seconds. Otherwise Pest is not started and the gate fails, listing the failing tests. If they only fail with the worker variables, the mutants are run in a single process instead.

Each of these runs has a temporary directory of its own (`TMPDIR`), removed afterwards, so that what a failing run leaves behind does not fail the next one. A test that starts further processes in a mutant's process (PHPUnit's `RunTestsInSeparateProcesses`) has them inherit `PEST_MUTATION_TESTING` without Pest's wrapper: a bootstrap must not assume the wrapper is there (`StreamWrapper::disable()` then raises a notice, `file:// was never changed`, which fails the test).

Two causes have been seen. **Patchwork** (Brain Monkey loads it) stacked on Pest's stream wrapper breaks directory reading ([antecedent/patchwork#235](https://github.com/antecedent/patchwork/pull/235)). **Pest's wrapper itself** takes every `flock()` as a shared lock and reports a file nobody may read as absent, so a test of locking or permissions fails whatever Patchwork does. Both go away when the test bootstrap, if `PEST_MUTATION_TESTING` is set, gives `file` back and loads `PEST_MUTATION_FILE` itself. It gives it back **only when Pest's wrapper is on it**: a process PHPUnit isolates a test in has the variables but not the wrapper. At the top of the bootstrap, before Patchwork is loaded (it puts its own wrapper on `file`):

```php
$mutating = is_string(getenv('PEST_MUTATION_TESTING')) && class_exists(\Pest\Mutate\Support\StreamWrapper::class);

if ($mutating) {
    $handle = fopen(__FILE__, 'r');
    $wrapped = (stream_get_meta_data($handle)['wrapper_data'] ?? null) instanceof \Pest\Mutate\Support\StreamWrapper;
    fclose($handle);

    if ($wrapped) {
        \Pest\Mutate\Support\StreamWrapper::disable();
    }
}

// Patchwork, Composer's autoloader and the helpers of the tests are loaded here

if ($mutating) {
    require (string) getenv('PEST_MUTATION_FILE');
}
```

That `require` is enough for a file that can be included on its own. For `__DIR__` and `__FILE__` in the mutated file, see the framework's `tests/bootstrap.php`.

**To check a repository without a mutation pass** (a few runs of the suite, no gate, no report):

```bash
vendor/bin/pollora-qa check --mutation-canary                          # the whole repository
vendor/bin/pollora-qa check --mutation-canary=src/A.php,src/B.php      # some files, as the limited scopes do
```

It exits 1 when no score could be believed, whatever the mode of the repository, and when `pest-plugin-mutate` is not installed. Give it a coverage driver, as the mutation run has.

- **Cost.** Two runs of the suite or of the covering tests (the control, then one per file; more when a run fails, which is tried again, and without the worker variables), then the canary: nothing more when a bootstrap loads the mutant at once (the framework: 0.1 s) or when a test includes the file early; otherwise as long as the suite takes to reach the file. A refusal costs a whole run of the suite.
- **What it cannot see.** Pest selects tests line by line: a test that only fails in the smaller selection of one line is not seen, nor a flaky one: they count as killed. A test that fails only now and then as a mutant: a failing run is tried once more, Pest tries each mutant once. A test that is slower as a mutant than Pest's own limit (the duration of its first run, plus 5 s or 20 %) yet within the limit above. A wrapper replaced only by some tests, which the probe does not run before the file is read. For the whole repository, a bootstrap that loads some files and not others. A `pest --mutate` run outside `pollora-qa`.

**Two traps of Pest's mutation runner.**
- Pest builds its `--filter` as regex alternatives, one per test that covers the mutated line. Past the PCRE compilation limit (about 64 KB) the filter is invalid, no test runs and the mutant is counted as surviving. The lines covered by almost the whole suite are the ones concerned, and adding tests can lower the score there: fewer, broader tests (datasets folded into loops) beat more of them.
- A class Composer loads from `files` (a `register.php`, a helper) is loaded before Pest mutates anything, so its mutants always survive. `--class=` does not narrow the run, and a test file edited during a run makes it die silently.

### Mutation score of the changed files

A whole mutation run grows with the size of the repository: the framework's did not finish in thirteen hours, so it cannot run in a pull request. A repository in that case declares, in `.harness.json`:

```json
"mutation": { "scope": "changed", "min": 80 }
```

`check --mutation` then mutates only the PHP source files the change touches (`pest --mutate --path=a.php,b.php`, after the suite's one full run) and the *Mutation testing (changed files)* gate holds their score to `min`. It blocks in `enforce` mode, unlike the whole-repository gate, which is observed by default.

- **What counts as a changed file.** The files added, copied, modified or renamed between a base and `HEAD`, under the directories Composer autoloads classes from (`autoload.psr-4`, `psr-0`, `classmap`; `src/` when there are none), minus the files of `autoload.files` (PHP includes them before Pest mutates anything, so their mutants always survive), tests, `vendor/` and deleted files. The base is `HEAD^1`, which on the merge commit a pull request's CI checks out is the target branch before the change, and on the merge commit of a push is the branch before it; `--mutation-base=REF` gives another. The reusable workflow sets `fetch-depth: 2` for such a repository, because a one-commit checkout has no base. A base git cannot resolve fails the gate, saying what to do: it never falls back to the whole repository.
- **`min` is a threshold, not a ratchet.** The score of some files says nothing about the files of the next pull request, so it is never recorded, tightened or compared with `ratchet.msi`: a pull request that touches a file at 100 % does not raise the bar for the next one. Without `min`, the gate only reports the score. Use either `ratchet.msi` (the whole repository) or `mutation.scope: "changed"`; with both, the scope decides and `msi` reads `unmeasured`.
- **`"scope": "added"` holds only the new files.** When the existing files score unevenly (the framework's, measured one by one: from 51 % to 100 %, median 74 %), a minimum high enough for new code blocks every change to an old file for mutants it did not write. `"mutation": { "scope": "added", "min": 80 }` mutates only the files the change adds (`git diff --diff-filter=A`) and holds them to `min`; the *Mutation testing (new files)* gate says how many modified files it did not mutate. New code is held to the bar, old code is left to the coverage gate and to the day someone touches it on purpose.
- **A score is computed on whole files, not on changed lines.** A mutant that survives in a file you touched counts even if you did not write it, so a one-line change to a weakly tested file can fail the gate. The failure lists the surviving mutants (`UNTESTED src/A.php > Line 61: ...`): add the test that would fail without that line, or raise the score of the file. Choose `min` from a measure of the files that change most, not from the repository's average.
- **Nothing to mutate passes.** A change without a source file, or whose files Pest creates no mutation in (an interface prints `Mutations: 0 tested`, `Score: 0.00%`, which measures nothing), passes with a note.

### Mutation score of the changed files, relative to the base

`"scope": "added"` leaves the existing files alone, so a change can lower the score of a file it edits and nothing says so; `"scope": "changed"` holds them to one minimum, which blocks a change for mutants it did not write. `"changed-relative"` asks what a reviewer asks: **did this change make the tests of this file weaker?**

```json
"mutation": { "scope": "changed-relative", "min": 80, "tolerance": 2, "min_mutants": 5, "max_files": 3, "max_minutes": 15 }
```

Only `scope` is needed; the other keys are optional and shown with their defaults, except `min`, which has none: without it, a new file or a file whose base cannot be measured is only reported. `check --mutation` lists the source files the change touches (the same list as `changed`), measures **each file on the change and on the base**, and the *Mutation testing (changed files, relative)* gate refuses a file whose score fell. It blocks in `enforce` mode. In the reusable workflow it runs on pull requests only, release pull requests excluded (see below). The base is `--mutation-base` (`HEAD^1`, the target branch on a pull request's merge commit: the `fetch-depth: 2` of the workflow already provides it); `pollora-qa check --mutation --mutation-scope=changed-relative --mutation-base=origin/develop` runs it by hand.

- **In CI, on pull requests only, and not on a release pull request.** The question is about a change, and after a merge or at night the change is `HEAD^1..HEAD` of the branch: a whole release, past `max_files`, for a red `main` that no commit can fix. So on every event other than `pull_request` (push, tag, schedule, manual run, `merge_group`) the *Quality gates* job of `php-package.yml` does not run the gate, and says so in a notice and in the job summary. The gate is then absent from the report: it is not a pass. What runs instead depends on `ratchet.msi`:
  - **No `ratchet.msi`:** no mutation runs.
  - **A `ratchet.msi`:** the whole repository is mutated (`--mutation-scope=all`) and the *Ratchet* step compares the score with the floor, as for a repository without `mutation.scope`. On a pull request the floor is not compared (`msi` is not measured on purpose). This pass takes as long as the whole repository does: raise [`quality-timeout-minutes`](#a-longer-time-limit-for-the-quality-job) if needed, and do not record `msi` in a repository whose whole run cannot finish. Until 0.1.37 a limited scope never compared `msi`, on any event.

  This is a rule of the workflow: `pollora-qa check --mutation` runs the gate wherever you type it. A push straight to `main`, without a pull request, is not compared with its base.
- **Not on a release pull request.** It carries every file of the release, past `max_files`. A release pull request is exactly all of: the event is `pull_request`, the head branch is `develop`, the base branch is the repository's default branch, and the head branch lives in the same repository. Anything else keeps the gate: a pull request into `develop`, a hotfix or feature branch into the default branch, `develop` into another branch, and a fork that has a branch named `develop` (it would otherwise be a way around the gate). On a release pull request nothing is mutated, with or without `ratchet.msi`, and a notice and the job summary say so: the gate is absent from the report, not passed. With a `ratchet.msi`, the *Ratchet* step shows `no report` for `msi` and compares nothing (its note asks for an Infection log: ignore it here); the floor is compared by the whole pass of the push to the default branch after the merge, which needs the calling workflow to run on that push, as the one `pollora-qa init` writes does. **What is given up:** the files of a release are not compared with the default branch. What went into `develop` through a pull request was judged there; a direct push to `develop`, an admin merge over a red gate and the interaction between pull requests are not seen.
- **One file, one pass, two sides.** Pest prints one score for a whole run, and in parallel mode no per-file count, so each file is a `pest --mutate --path=<file>` run of its own, on the change and on the base. Each pass repeats the suite's initial run first. The base is a temporary `git worktree` outside the repository, with its own tests, whose `vendor/` is a **copy** of the repository's (never a link: Composer resolves `src/` from the real path of `vendor/`, and a link would give the change's score as the base's; a copy also keeps the exact dependencies, which `composer install` would not without a lock file, and needs no network). It is removed when the gate is done, whatever happens. Files git ignores (`.env`, generated fixtures) are not in it: a suite that needs one reads `base unmeasured` below.
- **A drop is refused beyond `tolerance` points, and only when more mutants survive.** A score moves without any change to the code (a timeout counts as caught or not with the load of the machine: 89.18 % then 90.53 % on the same code), so up to 2 points are noise. It is a number of points on purpose: the same one lost mutant is 0.5 points in a file of 200 mutants (absorbed) and 12.5 in a file of 8 (refused); a file with fewer than 50 mutants sees every mutant it loses. A drop that adds no surviving mutant is not a regression either: deleting well-tested code lowers the score and weakens nothing, and the line says so.
- **`min_mutants`.** A file with fewer than 5 mutants, on the base or on the change, is **listed as too small to judge**: it neither fails nor counts as green, and the gate says how many. 8 mutants are judged, 3 are not.
- **New files** (absent from the base) are held to `min`, as `added` does; a renamed file is compared with its old path. A file with no mutant on the change (an interface) is listed, not failed; with no mutant on the base, it is held to `min`.
- **Base unmeasured.** When the base cannot be measured (its tests fail there, a guard below refuses its score, its suite needs a file git ignores), a regression cannot be proved: the file is held to `min`, and the line says `base unmeasured` with the reason. The change must reach the fixed floor, so that a broken base never makes a file pass for free.
- **Nothing is measured twice for nothing.** The change is measured first. A file at or above `100 - tolerance` cannot have dropped beyond the tolerance, so its base is not measured (the line says so).
- **A score is refused rather than believed**, on the change and on the base, in the cases that already gave a false figure: the tests fail on unchanged code run as a mutant (above); Pest could not write its temporary files (every mutant process fails and counts as killed: 100 %; the gate fails and names the directory); the test bootstrap loads **Patchwork** (Brain Monkey does) and Patchwork's stream wrapper replaced Pest's, so the mutants never load (every one survives) or the process dies (every one counts as killed, 100 % that no test earned). Pest's compact report shows neither, and the line `\Patchwork\CodeManipulation\Stream::reinstateWrapper();` also appears in the output of a healthy run (the framework's), so the gate does not read the output for it. It refuses on what it can see: a repository that has `antecedent/patchwork` and no test bootstrap (`tests/*.php`, `tests/*/*.php`, `phpunit.xml*`) that reads `PEST_MUTATION_TESTING` fails before any run, naming the fix (the framework's `tests/bootstrap.php` gives the wrapper back to Pest); and a run in which **at least 5 mutants all survive** (`859 untested, 0 tested`, the first failure above) is `unmeasured`. The check on the repository only proves the bootstrap *mentions* the variable: whether it works is checked by running it (see [A score needs proof that mutants load](#a-score-needs-proof-that-mutants-load)). A genuine 0 % on 4 mutants is judged like any other score; a genuine 100 % is believed.
- **The cost is capped, and the cap fails.** Two passes per file: measured on the framework, a small file of 49 mutants takes about three minutes a pass (see the CHANGELOG), so the defaults are `max_files: 3` and `max_minutes: 15`. A change that touches more files than `max_files` fails **before anything runs**, naming the files and what to do (raise the key, split the change, measure by hand): measuring only some would let the others through. A release pull request does not reach this limit in the reusable workflow, which does not run the gate there (above); run by hand, `check` applies it as to any other change. During the run, a pass that the passes so far say would run past `max_minutes` is not started; the gate then fails and lists each file it did not reach (`not measured: the time budget ran out`), never green. A pass already started is not interrupted, so the job can overrun by one pass; the `timeout-minutes: 30` of the quality job stays the hard limit.
- **What it does not see.** A change that only edits tests touches no source file and measures nothing (as with the other scopes). A regression spread over files the change does not touch is invisible. A renamed file is found by git's similarity detection (`-M`); a copied file counts as new. A suite whose result depends on files git ignores, or on a classmap-authoritative autoloader of the change, may read `base unmeasured`. The parallel and the single-process runs do not give exactly the same counts (above), and the gate does not know which of the two measured each side: that is what the tolerance is for.

### A longer time limit for the quality job

The *Quality gates* job stops after **30 minutes**. A repository whose whole mutation pass lasts longer on the runner (it runs on `main` and every night, with the `msi` floor of the ratchet) raises the limit with the input `quality-timeout-minutes`, in the workflow that calls the harness:

```yaml
jobs:
  harness:
    uses: Pollora/harness/.github/workflows/php-package.yml@v0.1.36
    with:
      quality-timeout-minutes: 90
```

It is available from 0.1.36. It is a number of minutes (GitHub accepts up to 360); the default is 30 and the other jobs keep their own limits. A timed-out job shows as **cancelled**, not failed, with the annotation "The job has exceeded the maximum execution time": read it before concluding that a gate failed. Measure first: the pass that takes a few minutes on a developer's machine can take several times longer on a two-core runner.

### Mutation on pull requests

The mutation run is 80 to 90 % of the *Quality gates* job (measured on 101 jobs of 33 repositories on 20 November: median job 134 s, 90th percentile 389 s, longest 912 s). A repository without `mutation.scope` mutated everything on every push, even a push that changes no PHP. So the reusable workflow now runs it differently on a pull request:

- **On a pull request** (event `pull_request`), a repository that mutates (it recorded `ratchet.msi`) runs `check --mutation --mutation-scope=changed --mutation-base=HEAD^1`: only the source files the pull request changes are mutated, and their score is held to `mutation.min`, or to **50 %** when `.harness.json` sets none (`MutationSettings::PULL_REQUEST_MINIMUM`: deliberately low, because a score is computed on whole files and the files of a repository score unevenly; a higher default would fail pull requests for mutants they did not write). A pull request that changes no PHP source file passes with `nothing to mutate`.
- **On every other event** (a push to `main` or `develop`, a tag, a manual run) the whole repository is mutated and its score is compared with `ratchet.msi`, exactly as before. The ratchet is therefore still measured on the main branch.
- **`inputs.mutation-on-pull-request`** of the reusable workflow: `changed` (the default) or `all`, which keeps the old behaviour (everything, on a pull request too). Any other value fails the job with a clear message. A repository that declares `mutation.scope` itself in `.harness.json` keeps it, whatever the input says; `changed-relative` runs its gate on pull requests only, release pull requests excluded ([above](#mutation-score-of-the-changed-files-relative-to-the-base)). No job name, `needs` or `if` changes.
- **The base.** `actions/checkout` on a `pull_request` event checks out the merge commit of the pull request, whose first parent is the target branch: `fetch-depth` is 2 for these runs so that parent exists, and the changed files are `HEAD^1..HEAD`. If the checkout is not a merge commit (a caller that checks out another ref), `HEAD^1` would be the previous commit of the branch and only that commit would be mutated, which is a silent false negative: the workflow detects it (`HEAD^2` missing), warns, and mutates the whole repository instead. A base git cannot resolve (`--mutation-base=origin/gone`) fails the gate and says what to do; it never passes without having measured.
- **The trade-off.** On a pull request the repository's score is **not measured** any more: the *Ratchet* step prints `msi` as not measured on purpose and compares nothing, and a regression of the whole score shows on the next push to the main branch, not on the pull request. A file the pull request does not touch, whose mutants survive, no longer fails it. A file it does touch is judged as a whole (see above), at `mutation.min` or 50 %: that is a floor for new code, weaker than the ratchet. A repository that wants the ratchet on every pull request sets `mutation-on-pull-request: all`.
- **Versions.** The flag is read by the `pollora-qa` installed in the repository: a repository still on 0.1.33 ignores the unknown option and mutates everything, as before. The option can also be used by hand: `pollora-qa check --mutation --mutation-scope=changed --mutation-base=origin/main`.

## What is in the box

- **Presets** in `presets/`: Pint, PHPStan (`base.neon`, plus `laravel.neon` and the opt-in `strict.neon`), Semgrep rules, and the Rector preset class `Pollora\Harness\Rector\Preset`.
- **ARCH-02 as a PHPStan rule**: code in a `Domain` namespace calls no global WordPress function (`pollora.noWordPressCallInDomain`).
- **Architecture tests** registered by `tests/Arch.php` from the layers found under the PSR-4 roots (`Domain`, `Application`, `Infrastructure`/`Adapter`, `UI`). The *Tests and architecture* gate runs `vendor/bin/pest`, which only runs the suites of `phpunit.xml`: when `tests/Arch.php` exists and no suite holds it, the gate also runs `vendor/bin/pest tests/Arch.php` and says so, so the rules cannot be skipped. Add `<testsuite name="Architecture"><file>tests/Arch.php</file></testsuite>` to `phpunit.xml` to run it with the others.
- **A layer shared with an installed package is judged on the repository's own classes.** Pest's architecture engine resolves a namespace through the PSR-4 prefixes of Composer's class loader, `vendor/` included. When a package under `vendor/` maps a namespace that starts a layer of the repository (`pollora/media` adds `Domain\Exceptions\*` to `Pollora\Media\…` of the framework), asking the engine about that namespace would load the package's classes too, and the engine fails describing them (`Typed property … ::$path must not be accessed before initialization`). `tests/Arch.php` reads `vendor/composer/autoload_psr4.php`; for such a namespace (ARCH-01, ARCH-03, CONV-03, CONV-05, CONV-07) it gives the engine the explicit list of the classes found in the repository's own PSR-4 directories instead of the namespace (a class name is resolved to its own file only, so nothing is read from `vendor/`). The test keeps its name and runs. Two cases stay out, and say so: a class that the package also has **under the same name** cannot be told apart from the package's (a skipped test `… (classes shared with a package)` lists them, reason `not evaluated: <rule> — N class(es) of <namespace> also exist under the same name in an installed package …`), and a namespace left with no class to judge is registered as skipped with the reason. A path repository (a symbolic link under `vendor/`) counts as installed.
- **CONV-02, observed: a WordPress registration is not described by a raw array in the public API.** Read with `nikic/php-parser` from the PSR-4 files, so no autoloader is needed. *Reported*, by two tests that complement each other: (1) a public method or global function with a parameter that can hold an array (typed `array`, nullable, in a union, or untyped) that flows, inside the method, into the arguments that describe a registration: `$args` of `register_post_type()`, `register_taxonomy()`, `register_block_type()`, `register_block_type_from_metadata()`, `register_rest_route()`, or any argument of `add_menu_page()` / `add_submenu_page()`; the flow follows the parameter, its elements, `array_merge($defaults, $args)`, `$args + $defaults`, the local variables assigned from them and closures (`add_action('init', function () use ($args) {…})`); (2) a public method, interface methods included, named `register…`, `create…`, `define…` or `add…`, about a post type, taxonomy, block, route or menu (the namespace, the class or the method says `PostType`, `Taxonomy`, `Block`, `Route` or `Menu`), with a parameter named `$args`, `$arguments`, `$config`, `$options` or `$settings` that can hold an array: this one catches a contract or a service that hands the array to a registry, which the flow cannot see. *Not reported*: the infrastructure layer (a class under an `Infrastructure` or `Adapter` namespace), where WordPress is called and the array is unavoidable: the rule asks that a typed object build it there; non-public methods; a class or method marked `@internal`; a literal array written inside the method (its signature takes no array); a parameter typed as an object. *Cannot be seen* (accepted false negatives): an array stored in a property and registered by another method, a registration through a wrapper (`$this->wp->registerPostType($args)`), a signature whose name or parameter name is none of the above, and the files of `autoload.files` (only PSR-4 classes are read). A file that does not parse is listed (`could not be parsed: not judged`), never skipped silently. The result is `incomplete` with the list while the rule is `observed`; it is not in `allow_rules` yet.
- **Architecture rules read from the files.** `CONV-08` (a class is `final` unless it is an extension point: an abstract class, a class named `…Builder`, a class that uses `Macroable`, or a class that another class of the repository extends), `CONV-09` (enforced since 0.1.35, see below; a facade is a thin front: its only method is `getFacadeAccessor()`, which returns `Service::class` or a binding name, and a `Service::class` of the repository is an interface, not a concrete class) and `CONV-12` (the tests of the repository, found under `tests/` and its `autoload-dev` roots without the `Fixtures` and `Stubs` directories, are `it()`/`test()` functions: a concrete class that extends a `…TestCase` and has a test method is reported; a test marked `#[RunInSeparateProcess]` or `#[RunTestsInSeparateProcesses]` (or the matching annotations) may stay a class, and so may a PHPStan rule test, which can only be one) read the files of the repository from their tokens, so they need no autoloader. A rule whose status in the catalogue (`resources/rules.php`) is `observed` never fails the *Tests and architecture* step: its test is reported as **incomplete** (`…` in Pest's output) with `observed, not blocking: <rule> — N problem(s)` and the list, and passes when there is nothing to report. Moving the rule to `enforced` in the catalogue turns the same test into a failure; the mechanism is generic (`Architecture::observed()`), so the next rule that is read from files is added the same way. **CONV-09 is enforced**: a facade with a method of its own, or one that resolves a concrete class of the repository, fails the *Tests and architecture* step, and an `allow_rules` entry with `classes` exempts a named facade (`Acme\Facades\Legacy`) or a namespace; CONV-02, CONV-08 and CONV-12 are still reported only. A rule with nothing to read (no class under the PSR-4 roots, no test file) is registered as skipped with `not evaluated: <rule> — <reason>`, never as a pass. They can be listed under `allow_rules` (`RuleExemption::EXEMPTIBLE`) before they are promoted; see [Promoting a rule](#promoting-a-rule).
- **A second reusable workflow**, `.github/workflows/template.yml`, for templates: see [Templates](#templates).
- **A reusable workflow**, `.github/workflows/php-package.yml`: quality gates, a test matrix (PHP × lowest/stable dependencies), a gitleaks scan, and the **downstream** job that runs the framework's tests against a changed core package.
- **Agent hooks** for Claude Code: style fixed after each edit, PHPStan on the edited file when its configuration covers it, protected threshold files (QA-02), the fast gates before the agent hands back.

### The one check to require

Both reusable workflows end with a job named **`Result`**. With the workflow call `init` writes (the job is called `harness`), the status check is **`harness / Result`**: require that one in the branch protection or the ruleset. The other job names carry the profile and the mode (`Quality gates (core, enforce)`) or a PHP version, so requiring them blocks every merge the day one of those changes.

`Result` fails when a job it needs failed or was cancelled. Observe mode keeps its meaning for failures: a failure that the job's `continue-on-error` already ignores (every job but `Read .harness.json` and *Quality gates* in observe mode, the API contract on a pull request labelled `breaking`, the `observed` jobs of a template) does not fail it. A skipped job (no `wordpress-integration`, no `downstream`) passes. A cancelled job fails it, observed or not, since nothing was checked; GitHub reports a job that exceeded its time limit as cancelled, so in observe mode too a timeout turns `Result` red. It runs with `if: always()`, because GitHub counts a skipped required check as passed. A caller that filters the whole workflow call out (`paths`, or an `if` on its job) never reports the check, and a pull request that requires it stays pending.

### Which harness version CI runs

The rules live in the CLI (`pollora/harness`, installed by Composer), not only in the workflow. What follows applies to a workflow called at the release that introduced it (see the CHANGELOG) or a later one: an older tag installs the newest release the constraint allows.

| Repository | What CI installs |
| --- | --- |
| No `composer.lock`, workflow called at a release tag (`@vX.Y.Z`) | That version, in the *Quality gates*, *Tests* and *WordPress integration* jobs (`composer update --with=pollora/harness:X.Y.Z`, `composer.json` unchanged). A new rule arrives with the pull request that raises the tag. |
| `composer.lock` committed | The *Quality gates* job installs the locked version, and warns when it differs from the tag. The jobs that run `composer update` resolve it anew, as before. |
| Workflow called at a branch or a commit, or `pollora/harness` required as a `dev-` version | What Composer resolves, with a notice. |

When the constraint of `composer.json` starts above the tag (`^0.1.40` with a workflow called at `@v0.1.39`), Composer refuses and the job fails: raise the tag. The tag is read from [`job.workflow_ref`](https://docs.github.com/en/actions/reference/workflows-and-actions/contexts#job-context), which is not available on GitHub Enterprise Server; an empty value is warned about and Composer resolves as before. `template.yml` installs no CLI: it reads its presets from the commit it was called at (`job.workflow_sha`).

## Templates

A template (the skeleton, a theme, a plugin or a module) is copied into every project built from it, so it cannot carry the harness: `pollora/harness` in its `require-dev` would be installed by every `composer create-project`, and an `AGENTS.md` at its root would land in every project. `template.yml` checks it from the outside instead (the one file it may carry is an optional `.harness.json`, [below](#a-harnessjson-for-a-template)). The template adds one file, `.github/workflows/harness.yml`, which the scaffolders strip:

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
    uses: Pollora/harness/.github/workflows/template.yml@v0.1.41
    with:
      mode: enforce # observe reports and never blocks
      observed: '' # gates reported but never blocking: secrets, style, security, audit, ratchet
    permissions:
      contents: read
```

It runs the gates that need nothing but the source: the secrets in the history (SEC-08), the code style (Pint, the template's own `pint.json` or the Laravel preset), the security patterns (SEC-01 to SEC-07 and CONV-10, with the rules of the version the workflow is pinned at) and the dependency audit of a committed `composer.lock` (SEC-09). PHPStan, Rector, the dependency analysis, the tests matrix, the API contract and the downstream run need a package: a template keeps its own tests in its own workflow.

A template holds placeholders the scaffolder fills in (`%theme_namespace%`, `%plugin_name%`). They are not PHP, so Pint would fail to parse the file: it runs on a copy where each one is an identifier. `observed` lists the gates a template reports without blocking, the way `.harness.json` does for a package: a theme with hundreds of `{!! !!}` it has not yet been through is `observed: security` until it has.

### A .harness.json for a template

A template may carry one optional file so that a floor can be recorded for it, which a template without a file cannot do (the type coverage of CONV-11 is the first use). `template.yml` reads `.harness.json` at the root, or failing that `.github/harness.json` (a directory the release archives leave out, so the file does not travel into the projects built from the template: prefer it when the template is distributed as an archive; the root file is copied by a scaffolder that copies the whole repository). A template without the file is checked exactly as before: the new `Ratchet (QA-01)` job finds nothing to read and passes at once, and no other job changes.

The file is a subset of a package's, and the profile may be left out (it is then `template`; `project` is accepted, any other profile is refused):

```json
{
    "profile": "template",
    "ratchet": {
        "type_coverage": 88.3
    }
}
```

| Key | What `template.yml` does with it |
| --- | --- |
| `ratchet.type_coverage` | The `Ratchet (QA-01)` job measures the share of typed declarations from the source alone (nikic/php-parser: no autoloader, no `composer install` of the template, `%placeholder%` markers read as identifiers) and fails a measure lower than the floor, with 0.05 point of tolerance. Without a floor nothing is compared. |
| `ratchet.coverage`, `ratchet.phpstan_level`, `ratchet.phpstan_baseline`, `ratchet.exempted_rules`, `ratchet.exempted_classes` | Compared when they can be measured: a Clover report at one of the usual places, a `phpstan.neon` or baseline, `allow_rules`. A template does not run its tests here, so a recorded `coverage` is reported as "no report" and not compared. `msi` is never measured. |
| `npm_audit.allow` | Read by the npm audit job, as before. |
| `slow_step_share`, `observed` | Read by the report of the ratchet job (a step flagged slow, a step named in `observed` reported without blocking). |
| `allow_commands`, `allow_rules` | Validated like in a package. No template gate uses `allow_commands`; `allow_rules` feeds the `exempted_rules` and `exempted_classes` floors. |
| `mode` | Ignored: the `mode` input of `template.yml` decides whether a drop blocks (`enforce`) or only reports (`observe`), and `observed: ratchet` turns the job into a report. |

A value that cannot be compared, a key out of range (`"type_coverage": 120`), an unknown profile or a package profile fails the job with the file named. `.harness.json` stays a protected file (QA-02): a person raises or records a floor, an agent does not.

```bash
# in the template: write the minimal file (the profile and the floor measured now, rounded down to a tenth)
vendor/bin/pollora-qa init --profile=template            # or php path/to/harness/bin/pollora-qa init --profile=template
vendor/bin/pollora-qa init --profile=template --config=.github/harness.json
# later: raise the floors to what the code reaches (never lowers them)
vendor/bin/pollora-qa ratchet --update
# what the job runs
vendor/bin/pollora-qa check --template --enforce
```

`init --profile=template` creates nothing but that file (no `phpstan.neon`, `tests/Arch.php`, `AGENTS.md` or workflow), and a file that already exists keeps every key it has.

## Integration tests against a real WordPress

Pollora packages test against Brain Monkey, which doubles WordPress. That is fast, and it proves the logic, but a defect that only a real WordPress shows passes the CI: `register_post_meta()` called before WordPress is loaded was found by the framework's downstream job, not by the package's own tests. The reusable workflow has an **optional** job for that. It is off by default: while the input is empty the job is skipped, nothing starts, and no other job changes ([`Result`](#the-one-check-to-require) passes a skipped job).

### Turning it on

```yaml
jobs:
  harness:
    uses: Pollora/harness/.github/workflows/php-package.yml@v0.1.41
    with:
      wordpress-integration: tests/Integration # the suite; empty (default) = off
      wordpress-version: latest # latest (default), nightly, 6.9, 6.9.1
      wordpress-plugins: action-scheduler # optional, wordpress.org slugs, comma-separated
    permissions:
      contents: read
```

The job is named `WordPress integration PHP <version>` (one per version of `php-versions`, 8.3 and 8.4 by default). It installs WP-CLI (a pinned version, checked against its published SHA-512; the download is retried), then WordPress with it under `$RUNNER_TEMP/wordpress` (outside the repository, so no linter reads it), against a MySQL 8.0 service container (digest-pinned), installs the plugins asked for, then exports `WP_PATH` and `WP_CORE_DIR` (the directory holding `wp-load.php`) and `WP_TESTS_DB_HOST`, `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD` to the tests. `WP_DEBUG` is on and cron is off (the `wp-config.php` constants are all guarded with `defined() ||`, so a bootstrap that defines them first, as the template does, never triggers a *Constant already defined* warning). It then starts the suite, in this order: the `test:integration` script of `composer.json` when there is one (the repository owns the command, as `hook` does with `composer test:wordpress`); otherwise `vendor/bin/pest --test-directory=<wordpress-integration> -c phpunit.integration.xml` when that file exists (see *Why `Pest.php` must not declare doubles of WordPress*); otherwise `vendor/bin/pest <wordpress-integration>`. The inputs are validated (a path inside the repository, a version, plugin slugs) before anything runs. Like the *Tests* job it only reports in observe mode and blocks in enforce mode, where its failure also fails [`Result`](#the-one-check-to-require).

### The bootstrap and a first test

`stubs/wordpress/` holds a bootstrap, a PHPUnit configuration and a test that proves a real WordPress is loaded. Copy them:

```bash
mkdir -p tests/Integration
cp vendor/pollora/harness/stubs/wordpress/bootstrap.php.stub tests/Integration/bootstrap.php
cp vendor/pollora/harness/stubs/wordpress/WordPressLoadedTest.php.stub tests/Integration/WordPressLoadedTest.php
cp vendor/pollora/harness/stubs/wordpress/phpunit.integration.xml.stub phpunit.integration.xml
cp vendor/pollora/harness/stubs/wordpress/unit-bootstrap.php.stub tests/bootstrap.php # only when the unit suite has no bootstrap yet
```

The bootstrap loads Composer's autoloader, then `wp-load.php` from `WP_PATH`, and stops with a clear message when it is missing. `phpunit.integration.xml` keeps the suite apart from `phpunit.xml`: **exclude `tests/Integration` from the suites of `phpunit.xml`**, otherwise the unit run would load WordPress too. The example test checks `function_exists('register_post_meta')`, `get_bloginfo()`, the database, a real hook and a real meta registration; if the bootstrap did not load WordPress, or a Brain Monkey stub were standing in, it fails. Locally: install WordPress anywhere (WP-CLI, SQLite or MySQL) and run `WP_PATH=/path/to/wordpress composer test:integration`, with this script in `composer.json`:

```json
"test:integration": "pest --test-directory=tests/Integration -c phpunit.integration.xml"
```

### Why `Pest.php` must not declare doubles of WordPress, and where they go

Pest loads `tests/Pest.php` and the files of `tests/Helpers/` **before** the PHPUnit bootstrap, in every run, even with `-c phpunit.integration.xml`. A unit suite that declares its doubles of WordPress there (`WP_Error`, `WP_REST_Request`, `WP_Query`, `WP_CLI`, `wp_doing_ajax()`, `wp_parse_args()`...) therefore has them declared when the integration bootstrap loads the real WordPress, which declares them again: `Cannot redeclare class WP_Error`, and Pest exits with 255 and no message. Found by deploying the job on eleven packages.

Two rules, both applied by every package that runs the job:

1. **Declare the doubles in `tests/bootstrap.php`**, referenced by `phpunit.xml.dist` (`bootstrap="tests/bootstrap.php"`); `stubs/wordpress/unit-bootstrap.php.stub` is the example. The unit suite reads that bootstrap and is unchanged; the integration suite reads `tests/Integration/bootstrap.php` (its own `phpunit.integration.xml`) and never sees the doubles. `Pest.php` keeps `pest()->extend(...)`, `beforeEach()` and the like.
2. **Run the integration suite with `pest --test-directory=tests/Integration -c phpunit.integration.xml`** (the `test:integration` script above). With a test directory, Pest looks for `Pest.php` and the helpers in that directory, not in `tests/`, so what only the unit suite needs is not loaded. The job runs exactly this command when the repository has no `test:integration` script, and the script itself when it has one.

The bootstrap template also guards `define('DISABLE_WP_CRON', ...)` with `defined() ||`, because the `wp-config.php` of the job defines it too: a second `define()` is a warning, which breaks the tests that compare the output of a sub-process and makes Pest exit without a test.

**Keep the suite out of the release archive.** `phpunit.integration.xml` sits at the repository root, so the *Distribution archive* step (QA-06) reports it as a development file that would travel in the archive Composer downloads. Run `vendor/bin/pollora-qa archive --fix` once: it writes the missing `export-ignore` line (the `tests/` folder is already covered by `/tests export-ignore`). Found by the first pilot of this job, on `Pollora/hook`.

### What it costs, and when to turn it on

Measured on 26 November 2026, each *WordPress integration* job as a whole (MySQL start, WordPress download and install, Composer and the suite) lasted 57 to 88 seconds on `Pollora/headless` and `Pollora/hornet`; the first pilot, on `Pollora/hook`, took 37 and 46 seconds. A larger suite adds its own time. The jobs run in parallel with the others, so a pull request waits only if this job is the slowest. WordPress archives and plugin archives are cached (restored on every run, written only by runs that are not pull requests, like Composer's cache), and `latest` is resolved to a version number first so the cache key is exact.

Turn it on for a repository whose code calls WordPress functions, hooks or the database in ways a double cannot reproduce: hook registration order, `register_*`, queries, options, cron, REST routes, anything that depends on WordPress being loaded. Leave it off for pure-PHP packages with no WordPress call (the ones that already pass on doubles prove nothing more here), and keep the suite small: what a double already proves belongs in the unit suite. MySQL 8.0 is used because it is what hosts run and what `hook` already tests on; it is past its upstream support, so the image will move to 8.4 once a pilot shows the authentication plugin of the client works with it.

## Adapter contract tests

A package that declares a port (an interface) and several adapters promises that the adapters are interchangeable. Each adapter has its own tests, written by whoever wrote it, so nothing says the adapters answer the same way to the same question. A **contract test** is one suite written against the port, run once per adapter: a difference is a failing case of one adapter, not something a reader has to notice. In `media`, the three drivers of `ThumbnailDriver` (the files of the library, Bunny, imgproxy) were each tested on their own and all green; the same cases run against the three found five differences (see below).

### The shape: a dataset of adapters, one suite of cases

Pest functions are enough, with no abstract class: the adapters are a **dataset** (one row per adapter), the contract is a file of `it()` with `->with('adapters')`, and adding an adapter is adding a row. An abstract PHPUnit test class (one subclass per adapter that supplies a `createAdapter()`) would do the same, but it is CONV-12 turned inside out: classes for tests, a hierarchy to follow, no dataset to combine with the cases, and a failing adapter is named by a class instead of by a row. The only thing a class would give is a `setUp()` shared by the cases of one adapter, and a factory closure that builds the adapter in each test does that.

`stubs/contract/` holds the template, a worked example for a toy port (`Store`: `put`, `get`, `forget`, with an in-memory and a file adapter). Copy it and rename:

```bash
mkdir -p tests/Contract/Datasets
cp vendor/pollora/harness/stubs/contract/Datasets/Contract.php.stub tests/Contract/Datasets/Contract.php   # as it is: the helper
cp vendor/pollora/harness/stubs/contract/Datasets/Stores.php.stub tests/Contract/Datasets/Stores.php       # your adapters
cp vendor/pollora/harness/stubs/contract/StoreContractTest.php.stub tests/Contract/StoreContractTest.php   # your cases
```

Then add the suite to `phpunit.xml` (`<testsuite name="Contract"><directory>tests/Contract</directory></testsuite>`): the *Tests* job runs it with the others, there is no workflow input to turn on. Pest loads every file under a `Datasets` folder before the tests, so the dataset needs no `require`.

- **`Datasets/Stores.php`** lists the adapters: `'file' => ['file', static fn (): Store => new FileStore(...)]`. A row is `[name, factory]`; the name is what the report shows and what a known deviation is keyed by, the factory builds the adapter when the test starts, with whatever the adapter needs (a temporary directory, a fake clock).
- **`StoreContractTest.php`** has the cases: each test receives `(string $adapter, Closure $make)`, plus the columns of any further `->with()` for the cases of one test (the odd keys, the empty value). Write what a caller may rely on and nothing an adapter is free to do differently (format, speed, limits, the sizes it refuses).
- **`Datasets/Contract.php`** is `contractCase()`, and is the same for every port. It is for a difference the contract found and nobody has decided yet. `STORE_KNOWN_DEVIATIONS` maps `'adapter|case' => reason` (what differs, and file and line); wrap the assertions of the case in `contractCase(STORE_KNOWN_DEVIATIONS, $adapter, 'case', function () { ... })`. The case still runs. While it fails it is reported as **skipped** with `KNOWN DEVIATION: reason` in every run; the day it passes the test **fails** and asks for the line to be deleted. So a listed difference never turns green by being left out and a fixed one cannot hide in the list; and an adapter that is not listed fails the suite like any test. Leave the list empty when it can be.

A contract that needs a service (Redis for a cache store, a real WordPress for a cron driver) belongs in the opt-in integration job (see above) and uses the same files: the dataset lists the adapters the job can build.

### What the template is tested with

`tests/Unit/ContractStubTest.php` copies the template into a small project (a port, two adapters that honour it, one that does not) and runs a real Pest on it: it passes on good adapters, it fails on the bad one and names the adapter and the case, a listed difference is a skip with its reason, and a listed difference that is fixed fails.

### A first port: `ThumbnailDriver` (media)

The contract found four differences between the three drivers and one defect they share, each with its proof in `media/tests/Contract`. The differences: the file driver signs a key with a leading slash differently from the other two (`SizedFileName.php:57`); the Bunny URL does not encode a `?` or `#` in the key (`BunnyThumbnailDriver.php:35`), so `sizeOf()` cannot read its own URL back (imgproxy does encode); the file and imgproxy drivers recognise the name of a size nobody declared, Bunny does not; imgproxy recognises the URL of a size it refuses (the `default` preset). The shared defect: `SizeGeometry` divides by the width and height of the original (`SizeGeometry.php:110-123`), so a scale or a contain of an original recorded as 0 pixels throws `DivisionByZeroError`. The contract shows it through the file driver only, because that driver is the one that calls the geometry; `DeclaredSizes::downsize()` (line 225) calls the geometry itself before any driver, so in WordPress every driver is reached (read in the code, not run). The contract corrected none of them: each was listed with its reason until it was decided. They have since been fixed ([Pollora/media#60](https://github.com/Pollora/media/pull/60)), and the list of known deviations of that suite is now empty.

### What it costs, and where to use it

Measured on `media` when the contract was written, before those fixes (PHP 8.4, no coverage driver, so coverage is not measured): 162 lines of cases (13 `it()`) and 42 lines of adapters and deviations for one port and four adapters, plus the 37 lines of `contractCase()` that the template provides; 140 test instances (130 passed, 10 skipped, all ten known deviations) in 0.37 to 0.51 s over three runs, Pest's start-up included. The whole suite takes 17 to 18 s with or without them, so the cost is below what a run varies by. Adding an adapter is one dataset row (about 150 characters); adding a case is one `it()` that every adapter runs; maintaining it is the list of known deviations, one line each. Use it for a port with **two real adapters or more**, or a fake that has to follow a real adapter (`ScheduleFake` and `WpCronDriver`): a port with one adapter has nothing to compare. A static check that every such port has a contract suite was measured and is not a rule yet (see *Status*).

## Reliability and security of the workflows

`php-package.yml`, `template.yml` and the harness's own `ci.yml` follow the same rules. Their contract does not change: same inputs, same job names. A repository that requires a status requires `Result` (see [The one check to require](#the-one-check-to-require)).

- **Pinned by commit.** Every third-party action is referenced by the full SHA of its tag's commit, with the tag in a comment (`actions/checkout@3d3c42e5… # v7.0.1`). Semgrep is installed with `pipx install semgrep==<version>` and gitleaks runs from `ghcr.io/gitleaks/gitleaks:<tag>@sha256:<digest>`. Dependabot (`github-actions` ecosystem, weekly) proposes the new SHA with its tag comment; to bump by hand, resolve the tag with `gh api repos/<org>/<repo>/git/ref/tags/<tag>` (when `object.type` is `tag`, follow `gh api repos/<org>/<repo>/git/tags/<sha>` to the commit), and for the image read the digest with `docker buildx imagetools inspect ghcr.io/gitleaks/gitleaks:<tag>`. Update the comment together with the SHA.
- **Least privilege.** `permissions: {}` at the top, `contents: read` per job, `persist-credentials: false` on every checkout, no `pull_request_target`, and no `${{ }}` of a value a third party controls inside a `run:` (they go through `env:`).
- **Composer authenticated with the job's token.** The steps that run Composer set `COMPOSER_AUTH='{"github-oauth": {"github.com": "${{ github.token }}"}}'`. Without it Composer downloads archives from `codeload.github.com` anonymously, and many jobs starting together on the same IPs get `HTTP/2 429` (seen on 20 November). `github.token` exists in a reusable workflow called from another repository: it is the calling run's token, with the permissions of the calling job (`contents: read`). The variable is set on the install step only, never at job level, and GitHub masks the token in the logs. A repository's `composer.json` scripts run in that step, so the token is one a collaborator's workflow already has.
- **Composer cache.** The download cache is restored for every run, and written only by runs that are not pull requests (`main`, `develop`, tags): a pull request cannot plant an archive that another branch later installs. The key is the PHP version, the stability and the hash of `composer.json` and `composer.lock`, with a prefix fallback.
- **Time limits.** Every job has a `timeout-minutes` (setup 5, quality 30, tests 15, WordPress integration 20, contract 15, secrets 10, downstream 20), several times the longest duration observed (4 minutes for the slowest job).
- **Concurrency.** A new push to a pull request cancels the run in progress for it; `main`, other branches, tags and manual runs have a group per run and are never cancelled.

## Promoting a rule

A rule is born `observed` (`resources/rules.php`): it reports and never blocks. Moving it to `enforced` turns every repository that still breaks it red, so the move is prepared first. For the maintainer, in this order:

1. **Measure.** `vendor/bin/pollora-qa promote-report` (or `--json`) prints, for each observed rule, what would block in the current repository if it were enforced: `would block (N problems)` with the first five, `clean`, `not applicable (nothing to read)` (a repository with no class or no test file: not a pass), or `no probe` (an observed rule the report cannot measure, listed rather than left out). It reads files and the recorded ratchet only: it runs no tool, writes nothing and always exits 0. CONV-08, CONV-09 and CONV-12 run the code of their Pest tests; CONV-11 compares the static type count with `ratchet.type_coverage` (the PHPStan half is the static analysis gate). Run it on every repository of the list before deciding: `vendor/bin/pollora-qa promote-report --root=/path/to/workspace [--only=a,b,c] [--json]` (or `promote-report <path>...`) measures every repository in one run and prints a rule x repository matrix (a number is the count of problems that would block, `n/a` nothing to read); `--json` gives `repositories` (the rows of each one, its static `type_coverage` and the `floor` to record in `ratchet.type_coverage`: the measure rounded down to 0.1 point) and `matrix` (`rule`, `repository`, `verdict`, `count`). A directory without `composer.json` is measured as not applicable, never dropped; a directory that does not exist or an invalid `.harness.json` is named on stderr and ends with status 1 after the matrix is printed. Without argument the report of the current repository is unchanged.
2. **Declare the motivated exemptions.** A repository that cannot satisfy a rule yet (published attribute classes that cannot become `final`) adds `{ "rule": "CONV-08", "reason": "…" }` to `allow_rules` (see above). The reason is required, the number of exemptions is the `exempted_rules` ceiling of the ratchet, and the exemption only takes effect when the rule is enforced.
3. **Record the floors.** `vendor/bin/pollora-qa ratchet --update` writes the metrics that have a floor, `type_coverage` for CONV-11 and `exempted_rules` for the exemptions. From then on a drop fails QA-01 in `enforce` mode and only warns in `observe`. `type_coverage` must be a number from 0 to 100 and every other ratchet key is validated too: a value the ratchet could not compare makes `.harness.json` invalid instead of being ignored.
4. **Promote.** Change the `status` of the rule to `enforced` in `resources/rules.php`, then run `php bin/pollora-qa agents` and commit `AGENTS.md` (the CI checks that its block matches the catalogue). Nothing else in the code changes: the test that was incomplete now fails.
A promotion done this way (0.1.35): CONV-09, PERF-01 and PERF-07. `promote-report --root=<workspace>` over the 36 directories that have a `composer.json` said `clean` or `not applicable` everywhere but the framework, whose four facades (`Option::forget()` and three facades over a concrete factory) were fixed in its own pull request first; the report was run again on the framework's `develop` and read `CONV-09 clean`, `PERF-01 clean`, `PERF-07 clean`. Only then did the three `status` lines change, together with `Plan::OBSERVED_BY_DEFAULT` (which lost `Performance budgets`: a gate listed there never blocks whatever the catalogue says). A rule the measure still finds in a repository is not promoted: it waits for that repository's fix.
5. **Raise the version.** Add the entry under `[Unreleased]` in `CHANGELOG.md` and release a new version. Each repository moves to the new tag by pull request, when it is ready. A repository that stays on the previous tag is not affected only where the CLI it installs stays too (see [Which harness version CI runs](#which-harness-version-ci-runs)): a repository without `composer.lock` called at a tag that pins it; a repository still on an older tag takes the new release through Composer; and with a committed lock only the *Quality gates* job holds the locked version, the jobs that run `composer update` (so the architecture rules of the *Tests* job) take the new one. A local `composer update` without a lock takes the latest release the constraint allows.

## Public surface

Projects built on Pollora rely on more than PHP signatures: the attributes and their parameters, the hooks Pollora fires, the configuration keys and the console commands. `pollora-qa surface --update` records them in `api-surface.json`, one line per entry:

```json
"attribute:Pollora\\Attributes\\WpRestRoute($permissionCallback)": "Pollora\\Attributes\\WpRestRoute\\Permission|string|null, optional = NULL",
"hook:pollora/media/stored": "hook",
"config:media.disk": "key",
"command:pollora:make:block": "command"
```

In CI, a pull request that changes the surface needs a label (QA-04): `api-change` when it only adds entries (a minor version), `breaking` when it removes or changes one, or adds a required parameter to an existing attribute (a major version, after a deprecation). The diff shows exactly what a release changes for its users. PHP signatures are compared with the latest tag by `roave/backward-compatibility-check` (QA-05).

## Distribution archive

Composer installs a package from the archive GitHub builds with `git archive`. Without `export-ignore` lines in `.gitattributes`, the tests, the CI workflows, the tool configuration and the agent files (including the ones `init` adds) travel with every install. The **Distribution archive** gate (QA-06) builds that archive and fails when a development file is in it, listing the lines to add. `pollora-qa archive --fix` writes them, and `init` adds the ones for the files it creates.

A directory ignored with `/tests export-ignore` cannot be seen file by file, which is why the gate reads the archive itself. A `template` or a `project` is not checked: its archive is the product. The gate is skipped where `git archive` cannot be built (no git, no commit yet).

The gate **blocks** in `enforce` mode, like the others; a repository that is not ready lists `Distribution archive` under `observed` in `.harness.json`. It started observed in 0.1.15, so that raising the harness turned no repository red, and blocks since 0.1.16 now that the repositories are aligned.

## Performance budgets

The **Performance budgets** gate (PERF-01 and PERF-07) reads files; it never starts a site. The framework measures the SQL queries of each type of page and the boot, cold discovery and thumbnail timings in its own browser tests (`tests/e2e`); the gate checks that those measures are still declared, still wired and never loosened. It blocks like the other gates (PERF-01 and PERF-07 are `enforced` since 0.1.35: measured on the 45 repositories of the ecosystem, only the framework declares budgets and it passes them); a repository without a budgets file or nightly measures gets `skipped` with the reason, never a failure. A repository that is not ready lists `Performance budgets` under `observed` in `.harness.json`.

**PERF-01, the query budgets.** The gate applies when the repository has a budgets file (default `tests/e2e/budgets.json`) and checks:

- the shape: `{ "pages": { "home": { "wordpress": 24, "laravel": 0 } } }`, every budget a whole number of queries (0 is a real limit) or `null`;
- the `null` budgets, listed as `measured only`: a page with no budget is not held to anything;
- the ratchet: a budget that **went up**, was turned into `null` or was removed since the base commit fails the gate. A budget never goes up without a written decision. The base is `HEAD^1` (the target branch on a pull request's merge commit, so the checkout needs `fetch-depth: 2`; the reusable workflow does that), or `--budgets-base=REF`. When the base is not in the checkout, the file is new there or the file of the base is unreadable, the gate says `not compared with <base>: <why>` and passes on the other checks: it never reports a comparison that did not happen.

**PERF-07, the nightly measures.** The gate applies when the repository has the measuring file (default `tests/e2e/specs/perf-metrics.spec.ts`) or declares `performance.nightly`. It checks that the measuring file and the comparing file (default `tests/e2e/bin/perf-compare.ts`) exist, that a workflow has an `on: schedule` trigger with a `cron`, that this workflow runs the measure and the comparison, and that the `--tolerance` it gives the comparison is at most 0.1 (an alert above 10 %); with no `--tolerance` it says the script's default applies and that it did not verify it. The reference a night is compared with is a CI cache entry in the framework, not a file, so the comparing script stands for it. This is a static reading: it proves the wiring is declared, not that last night passed.

In a repository that declares neither, the step is `skipped` with its reason (never `passed`). Where to look is set under `performance` in `.harness.json`, every key optional:

```json
{
    "performance": {
        "budgets": "tests/e2e/budgets.json",
        "nightly": {
            "measure": "tests/e2e/specs/perf-metrics.spec.ts",
            "compare": "tests/e2e/bin/perf-compare.ts",
            "workflow": ".github/workflows/ci.yml"
        }
    }
}
```

`nightly: {}` declares the nightly measures with the default paths, so a repository that lost the files is told so instead of being skipped. Paths are relative to the repository, without `..`; anything else makes `.harness.json` invalid. Without `workflow`, every file of `.github/workflows` is read.

## Observed Semgrep rules (SEC-10 to SEC-13)

`presets/semgrep/observed.yml` holds four rules that follow what a request sends to a dangerous call. They run in the *Security patterns (observed)* step of `pollora-qa check`, which reports and never blocks (it is observed by default), and are `observed` in the catalogue. A new Semgrep rule is born here; it moves to `common.yml` (and joins *Security patterns*) when the measure over the repositories is clean and a promotion is decided. The rules are taint rules: they follow a value inside one function (an assignment, a concatenation, a `wp_unslash()` or `sanitize_text_field()` in between), not across functions or files.

A **source** is what the visitor controls: `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_SERVER['HTTP_*']`, `REQUEST_URI`, `QUERY_STRING`, `PHP_SELF` and `PATH_INFO`, `filter_input()`, and (SEC-10 to SEC-12) a Laravel request: `$request->input()`, `query()`, `get()`, `post()`, `all()`, `header()`, `cookie()`, the `request()` helper and the `Request` facade. A comparison, a boolean or a number is not tainted (`$_GET['tab'] === 'a'`, `isset()`, `(int)`).

| Rule | Reports | Does not report |
| --- | --- | --- |
| SEC-10 | the request in `wp_redirect()`, `redirect()`, `redirect()->away()` / `->to()`, `Redirect::to()` / `::away()`, or a `Location` header (open redirect) | the value after `wp_validate_redirect()`, `home_url()`, `site_url()`, `admin_url()`, `get_permalink()`, `route()`, `url()`, `in_array()`, `absint()` or a cast; `wp_safe_redirect()` |
| SEC-11 | the request as the **URL** (first argument) of `wp_remote_get/post/head/request()`, `curl_init()`, `CURLOPT_URL`, `Http::get/post/…()` (SSRF) | `wp_safe_remote_*()`, the value after `wp_http_validate_url()`, `home_url()`, `rest_url()`, `absint()` or a cast; the request in the *body* or the headers |
| SEC-12 | the request as the path of `file_get_contents()`, `file_put_contents()`, `file()`, `fopen()`, `readfile()`, `unlink()`, `wp_delete_file()`, `copy()`, `rename()`, `mkdir()`, `rmdir()`, `include`/`require`, `load_template()`, `get_template_part()` (traversal, `php://`, a URL) | the value after `basename()`, `sanitize_file_name()`, `sanitize_key()`, `sanitize_title()`, a hash, `absint()` or a cast |
| SEC-13 | the request in `echo`, `printf()`, `wp_die()`, `die()`, `exit()` without escaping (`print` is not looked at: Pint forbids it next to `echo`) (reflected XSS); `sanitize_text_field()` is not an escape | the value after `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()`, `esc_textarea()`, `wp_kses*()`, `htmlspecialchars()`, `wp_json_encode()`, `absint()` or a cast |

An exception is a `// nosemgrep -- reason` on the line.

**Measure (45 repositories, whole tree without `vendor`, `node_modules` and `tests`, 2,684 PHP files read by Semgrep 1.186.0): no report for any of the four rules.** The 20 syntax errors are the `%placeholder%` files of the three theme templates, which Semgrep cannot parse and which `common.yml` skips the same way. The count is not a proof of absence: the ecosystem reads very little from the request (80 uses of `$_GET`, `$_POST`, `$_REQUEST` and `$_COOKIE` in 45 repositories, 9 `wp_redirect`, 10 `wp_remote_*`, 4 `Http::` calls, 4 `filter_input()`, 12 Laravel request reads), and each was read by hand: every value is validated, compared or used as an array key, and the OAuth redirect of `mcp-connector` goes through the registered redirect URI of the client before any `Location`. Each rule has a positive and a negative fixture (`tests/Fixtures/semgrep/src/Request*.php`), which is the witness that it detects.

Candidates of the issue that are **not** shipped as new rules:

- *A `$_GET` / `$_POST` / `$_REQUEST` read with no `sanitize_*` / `wp_unslash` before a sink*: without the sink it is noise. A syntactic version (the superglobal not inside `sanitize_*`, `wp_unslash()`, `absint()`, `isset()`, `empty()`, a cast or a nonce check) reported 6 places in the 45 repositories and all 6 were false positives (the value goes to a helper that sanitizes it, to a nonce check or to a `hash_equals()`): 100 %. The taint rules above are its useful form.
- *`wp_ajax_nopriv_` without a nonce*: SEC-02 already blocks every hand-registered `wp_ajax_*` hook.
- *`json_decode` then `extract`*: SEC-05 already blocks `extract()`.
- *`header()` with request data*: PHP refuses a header with a line break, so only `Location` matters (SEC-10).
- *`add_filter` / `add_action` callbacks that echo unescaped output*: the echo of a request value is SEC-13; an echo of any other variable cannot be told from an escaped one by a pattern (it would report the whole of every view).

## Rules

`vendor/bin/pollora-qa rules` prints the catalogue, from `resources/rules.php`. Each rule is `enforced` (fails the gate), `observed` (reported only) or `planned` (not checked by this version yet).

An exception to a rule is written on the line it concerns, with its reason: `// @phpstan-ignore <identifier> (reason)` or `// nosemgrep -- reason`. Nothing is silenced without a trace.

## Status

The harness no longer only measures: most repositories that adopted it run the gates in `enforce` mode, with a ratchet that only moves up (line coverage, PHPStan level and baseline, mutation score). A newly adopted repository stays in `observe` until its reports are clean; the `mode` of its `.harness.json` says which it is.

| | Rules | Which |
| --- | --- | --- |
| Enforced | 35 | style, PHPStan, architecture tests, dependency policy, Semgrep patterns (SEC-01 to SEC-07, CONV-10, and ARCH-05 on the framework), the names of hooks and commands (CONV-04, CONV-06), the downstream job (QA-03, which keeps the framework green), secrets, `composer audit`, the ratchet, the public surface, backward compatibility, the distribution archive, the performance budgets and nightly measures (PERF-01, PERF-07), the thin facades (CONV-09), the warm-cache and query-count tests of discovery and Colt (PERF-02, PERF-03, which run in those packages' own suites) |
| Observed | 8 | CONV-02 (no raw registration arrays), CONV-08 (final classes), CONV-11 (native types), CONV-12 (Pest functions), SEC-10 to SEC-13 (request data reaching a redirect, an HTTP call, a path or an echo) |
| Planned | 1 | CONV-01 |

**Why CONV-01 stays `planned`.** The rule ("the common case needs no configuration") was read four ways over the 45 repositories, with `nikic/php-parser`, and none is precise enough to report without noise. (1) A public static factory, or a public method of a `*Builder` / `*Factory` / `*Registry` / `*Registrar` / `*Manager`, with 4 required parameters (3 when one is a required `bool`), exceptions, interfaces, callbacks, `@internal`, Infrastructure, Console and Providers left out: 22 reports in 8 repositories, about 1 s; 5 were real (`Ability::create()` and `AbilityDefinition::reading()` / `writing()` with 7 required arguments, `Registry::table()` / `variables()`), 17 were not (12 view helpers of an admin screen, internal factories of the harness, a three-boolean predicate). Without the admin screens and from 5 required parameters: 6 reports, 3 real. (2) A public constructor with 3 or more required scalars: 55 reports, nearly all value objects, events and DTOs, whose fields are required by nature. (3) A required `bool` next to another required parameter: 10 reports, 3 real (`put($path, $contents, $dryRun)`), the others WordPress callbacks and setters. (4) Any public method with 4 or more required parameters: 62 outside constructors, about half of them WordPress hook callbacks or signatures imposed by a contract. Deciding that a parameter has an "obvious default" is a judgment on meaning, not on syntax, and the few real cases (6 to 10 in 45 repositories) are too few to show a false-positive rate under 10 %. The rule stays with its present tool: review, and the documentation examples run as tests.

**Why no rule checks that a port has a contract suite (yet).** A static check was measured, not written, with `nikic/php-parser` over the checkouts of the workspace: an interface with 2 or more concrete implementations in `src/`. That finds 26 interfaces in 13 of the 33 checkouts that have a `src/`, and most are not ports: `ConfiguresArguments` has 69 implementations, `CheckInterface` 26, `ThemeComponent` 7, `Command` 12 (strategies and markers, not interchangeable adapters). Narrowing by name (`*Driver`, `*Store`, `*Storage`, `*Repository`, `*Connector`, `*Adapter`, `*Gateway`, with or without `Interface`) leaves 6: `ThumbnailDriver` (media), `AsyncDriver` (hook), `ScheduleDriver` (schedule), `CacheStore` (object-cache), `ModuleStateConnector` and `ModuleRepositoryInterface` (framework): the list is right, but it is a naming convention, and an adapter named otherwise would escape it silently. A rule would be `observed` (it reports, never blocks), would say "the port `X` has N adapters and no test under `tests/Contract` that names it", and would need `TypeDeclaration` to record the interfaces a class implements (it records the parent class only). It is left as a proposal until two more ports have a suite and show that the convention (`tests/Contract`, the port named in the file) holds.

`pollora-qa rules` prints the catalogue. Done since the first phase: the mutation score (`check --mutation`, see [Mutation score and the ratchet](#mutation-score-and-the-ratchet)) and the blocking Semgrep rules. Integration tests against a real WordPress are an opt-in job (see [Integration tests against a real WordPress](#integration-tests-against-a-real-wordpress)). Adapter contract tests are a template and a pattern (see [Adapter contract tests](#adapter-contract-tests)); a static check that every port has a contract suite is not written (see below). The performance budgets are checked from their files (see [Performance budgets](#performance-budgets)); the measuring itself stays in the repository that owns a site.

## Contributing

Contributions are welcome: see the [contributing guide](https://github.com/Pollora/.github/blob/main/CONTRIBUTING.md). Report security issues privately, as described in the [security policy](https://github.com/Pollora/.github/blob/main/SECURITY.md).

## License

Pollora Harness is open-source software licensed under the [MIT license](LICENSE). © [RuBee group](https://rubee.group)
