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

The names are the ones `pollora-qa check` prints: `Code style`, `Static analysis`, `Rector`, `Tests and architecture`, `Dependency policy`, `Dependency analysis`, `Security patterns`, `Dependency audit`, `Public surface`, `Performance budgets`, `Ratchet`. Shorten the list as each gate comes clean.

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

**An exemption of an observed rule is declared, not applied.** CONV-08, CONV-09 and CONV-12 may be listed under `allow_rules` before they become blocking, so that the repository that cannot satisfy them yet says so, with its reason, on the day they are promoted. While the catalogue lists the rule as `observed` the exemption changes nothing: the rule keeps running and reporting (an exemption would hide findings for a rule that blocks nothing), the gate says `declared, not applied while the rule is observed: CONV-08 — <reason> (allow_rules)` and does not count it in `exempted`, and the exemption is still counted by `exempted_rules`, so its ceiling is recorded and can only go down. The day the rule is `enforced`, the same line skips it with `exempted: CONV-08 — <reason>`.

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

`rule` must be a rule that `tests/Arch.php` evaluates (ARCH-01, ARCH-03, ARCH-08, ARCH-09, CONV-03, CONV-05, CONV-07, CONV-08, CONV-09, CONV-12) and `reason` is required: an unknown rule, a rule checked by another gate, a rule listed twice or an entry without a reason makes `.harness.json` invalid, with a message that names the entry. `.harness.json` is a protected file (QA-02): a person adds the entry, an agent does not. `init` and `ratchet --update` keep it.

**An exemption of an observed rule is declared, not applied.** CONV-08, CONV-09 and CONV-12 may be listed under `allow_rules` before they become blocking, so that the repository that cannot satisfy them yet says so, with its reason, on the day they are promoted. While the catalogue lists the rule as `observed` the exemption changes nothing: the rule keeps running and reporting (an exemption would hide findings for a rule that blocks nothing), the gate says `declared, not applied while the rule is observed: CONV-08 — <reason> (allow_rules)` and does not count it in `exempted`, and the exemption is still counted by `exempted_rules`, so its ceiling is recorded and can only go down. The day the rule is `enforced`, the same line skips it with `exempted: CONV-08 — <reason>`.

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

A template (the skeleton, a theme) cannot carry a `.harness.json`. Its reusable workflow runs `pollora-qa npm-audit`, the same code as the `check` step, which reads the `npm_audit` key from `.harness.json` or, failing that, from `.github/harness.json` (a directory the release archives leave out), as a file holding only that key. `--config=FILE` names another file. `.harness.json` is a protected file (QA-02): a person adds the entry, an agent does not.

The **Dependency analysis** gate uses the repository's `composer-dependency-analyser.php` when there is one; without it, a harness preset ignores what is noise in a WordPress repository (WordPress classes and functions, which are not Composer packages, the usual PHP extensions, and the classes and libraries of the tests); a repository that needs more writes its own file and can start from the preset with `require`.

## Commands

| Command | Does |
| --- | --- |
| `pollora-qa check` | Every gate (G1 and G2): style, PHPStan, Rector, tests and architecture, dependency policy and analysis, Semgrep, `composer audit`, `npm audit` (observed, with the time-limited exceptions of `npm_audit`), the public surface (and the names of its hooks and commands), the performance budgets and nightly measures (observed), ratchet |
| `pollora-qa check --fast` | The fast gates (G0) on the files changed in the working tree; the whole tree where git is not usable |
| `pollora-qa check --strict` | Also fails when a gate could not run because a tool is missing (Semgrep, a `vendor/bin/` tool) |
| `pollora-qa check --coverage[=FILE]` | Runs the tests with coverage and compares the Clover report with the ratchet (default `.harness/coverage.xml`) |
| `pollora-qa check --mutation` | Also runs Pest's mutation testing (`pest --mutate --everything --covered-only`) and compares its score with the ratchet's `msi`; reported, never blocking by itself |
| `pollora-qa check --mutation --mutation-base=REF` | With `"mutation": {"scope": "changed"}` in `.harness.json`, the commit the changed files are counted from (default `HEAD^1`) |
| `pollora-qa promote-report [--json] [<path>... \| --root=<dir> [--only=a,b]]` | For each observed rule, what would block here if it became enforced (read only, see [Promoting a rule](#promoting-a-rule)); with paths or `--root`, one rule x repository matrix |
| `pollora-qa check --mutation --mutation-scope=all\|changed\|added` | What `--mutation` mutates in this run, over the `mutation.scope` of `.harness.json`. A limited scope is held to `mutation.min`, or to 50 % when the file sets none; an unknown value fails before any gate runs |
| `pollora-qa ratchet [--update]` | Compares the metrics with `.harness.json`; `--update` records improvements, never regressions |
| `pollora-qa deps` | Checks the declared dependencies against the profile |
| `pollora-qa surface [--update]` | Compares the public surface with `api-surface.json`; `--update` records it |
| `pollora-qa archive [--fix]` | Checks that development files stay out of the release archive (QA-06); `--fix` writes the missing `export-ignore` lines |
| `pollora-qa composer-audit [--locked]` | The Composer dependency audit alone (SEC-09), which tries again when Packagist cannot be reached; same code as the `check` step, used by the reusable workflow of the templates |
| `pollora-qa npm-audit [--config=FILE]` | The npm dependency audit alone (SEC-09), for a template that cannot carry the harness; same code as the `check` step |
| `pollora-qa rules [ID]` | Lists the rules, or explains one |
| `pollora-qa agents` | Regenerates the harness block of `AGENTS.md` |
| `pollora-qa hook <event>` | Agent hooks: `pre-edit`, `post-edit`, `stop` |

`check` writes `.harness/report.json` and, in GitHub Actions, a table in the job summary.

### Missing tools

A gate whose tool is not installed does not run, and is not a pass: the summary marks it `– no tool`, prints the command that installs the tool (`pipx install semgrep`, `composer require --dev laravel/pint`…), and ends with "Every gate that ran passed, but not every gate ran." instead of "Every gate passed."; the job summary and `report.json` say the same (`missing_tool`, `install`). Locally this is how Semgrep, which guards SEC-01 to SEC-07 and CONV-10, goes unnoticed; CI installs it. `check --strict` makes the missing tool fail the check, in `observe` mode too; a gate listed under `observed` is left out.

### Without git

`check --fast` takes the changed files from git. Where git cannot answer (a worktree created on the host and opened from DDEV, whose `.git` points outside the container; no git binary; a directory git does not trust), it says so and checks the whole tree: Pint without `--dirty`, PHPStan on every configured path. It is slower, and may report a file you did not touch, but the style gate no longer fails for a reason that is not style.

### Exempted rules in the ratchet

`exempted_rules` counts the architecture rules `allow_rules` exempts, and lower is better, like `phpstan_baseline`. It is only measured where it matters: a repository without `allow_rules` and without the key in `ratchet` shows it as `unmeasured` and nothing is recorded, so an existing `.harness.json` keeps passing as it is. In a repository that uses `allow_rules`, the first `pollora-qa ratchet --update` records the current count; from then on a count above the recorded one is `regressed` (QA-01 fails in enforce mode), and `ratchet --update` lowers the ceiling when an exemption is removed, down to `0`, never raises it. To exempt one more rule on purpose, a person raises the number by hand in `.harness.json`, a protected file (QA-02). `allow_commands` is not tracked by the ratchet.

### Coverage and the ratchet

`ratchet` and `check` compare the line coverage with `.harness.json` when they find a Clover report: the one given by `--coverage=FILE`, otherwise `.harness/coverage.xml` (what `check --coverage` writes), `.harness/clover.xml` or `build/logs/clover.xml`, and they print which one they read. Without a report, a recorded coverage reads `no report` and a note says where the harness looked and how to produce one; nothing is compared, and the ratchet does not fail for it. Produce a report with `vendor/bin/pollora-qa check --coverage` (or `vendor/bin/pest --coverage-clover .harness/coverage.xml`), and refresh it before trusting the comparison: an old file is read as it is.

### Mutation score and the ratchet

Line coverage says which lines the tests run, not whether they would notice a change. `check --mutation` runs `pest --mutate --everything --covered-only` (Pest 3.8 and later, with `pestphp/pest-plugin-mutate`; the step is skipped without it), reads the `Score:` line it prints and gives it to the ratchet as `msi`, in percent, higher is better. No `covers()` declaration is needed, and code no test reaches is left to the coverage gate.

The run is parallel. A suite that fails that way (a global function a helper declares, a test that depends on the order) prints no score, so the step repeats it once in a single process; when the tests themselves fail, there is no score and the step says so. The *Mutation testing* gate is observed by default: it reports the score and never fails a build. The ratchet fails only a repository that recorded an `msi` and let it drop. Record the first value with `pollora-qa ratchet --update --metric=msi:79.17`, using the score `check --mutation` printed.

**In CI.** The reusable workflow reads `.harness.json`: a repository that recorded `ratchet.msi` gets `check --mutation` in its *Quality gates* job, and one that did not is untouched. So adopting the gate is one commit: run `check --mutation` once, record the score, push. A mutation run takes from seconds to ten minutes or more, depending on the number of mutants.

**Record a floor below the score you measured.** The figure moves from a run to the next: a mutant that loops until Pest's timeout counts as killed or not depending on the load of the machine (89.18 % and 90.53 % on the same code), and the parallel and single-process runs do not give exactly the same count. Record about two points under what you measured, and raise it by hand when the score has stayed higher for a while (`ratchet --update` only raises, never lowers).

**A mutation run that cannot write its files.** Pest writes each mutant under `vendor/pestphp/pest-plugin-mutate/.temp/mutations`. If that directory is missing or read-only, every mutant process fails and counts as killed, so the score reads 100 % whatever the tests assert. The gates read Pest's `Failed to open stream` warning for that path and fail, naming the directory, rather than report the score. A fresh `composer install` has the directory; a CI that restores `vendor/` from a cache, or a container that deletes it, may not.

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

### Mutation on pull requests

The mutation run is 80 to 90 % of the *Quality gates* job (measured on 101 jobs of 33 repositories on 20 November: median job 134 s, 90th percentile 389 s, longest 912 s). A repository without `mutation.scope` mutated everything on every push, even a push that changes no PHP. So the reusable workflow now runs it differently on a pull request:

- **On a pull request** (event `pull_request`), a repository that mutates (it recorded `ratchet.msi`) runs `check --mutation --mutation-scope=changed --mutation-base=HEAD^1`: only the source files the pull request changes are mutated, and their score is held to `mutation.min`, or to **50 %** when `.harness.json` sets none (`MutationSettings::PULL_REQUEST_MINIMUM`: deliberately low, because a score is computed on whole files and the files of a repository score unevenly; a higher default would fail pull requests for mutants they did not write). A pull request that changes no PHP source file passes with `nothing to mutate`.
- **On every other event** (a push to `main` or `develop`, a tag, a manual run) the whole repository is mutated and its score is compared with `ratchet.msi`, exactly as before. The ratchet is therefore still measured on the main branch.
- **`inputs.mutation-on-pull-request`** of the reusable workflow: `changed` (the default) or `all`, which keeps the old behaviour (everything, on a pull request too). Any other value fails the job with a clear message. A repository that declares `mutation.scope` itself in `.harness.json` keeps it, whatever the input says. No job name, `needs` or `if` changes.
- **The base.** `actions/checkout` on a `pull_request` event checks out the merge commit of the pull request, whose first parent is the target branch: `fetch-depth` is 2 for these runs so that parent exists, and the changed files are `HEAD^1..HEAD`. If the checkout is not a merge commit (a caller that checks out another ref), `HEAD^1` would be the previous commit of the branch and only that commit would be mutated, which is a silent false negative: the workflow detects it (`HEAD^2` missing), warns, and mutates the whole repository instead. A base git cannot resolve (`--mutation-base=origin/gone`) fails the gate and says what to do; it never passes without having measured.
- **The trade-off.** On a pull request the repository's score is **not measured** any more: the *Ratchet* step prints `msi` as not measured on purpose and compares nothing, and a regression of the whole score shows on the next push to the main branch, not on the pull request. A file the pull request does not touch, whose mutants survive, no longer fails it. A file it does touch is judged as a whole (see above), at `mutation.min` or 50 %: that is a floor for new code, weaker than the ratchet. A repository that wants the ratchet on every pull request sets `mutation-on-pull-request: all`.
- **Versions.** The flag is read by the `pollora-qa` installed in the repository: a repository still on 0.1.33 ignores the unknown option and mutates everything, as before. The option can also be used by hand: `pollora-qa check --mutation --mutation-scope=changed --mutation-base=origin/main`.

## What is in the box

- **Presets** in `presets/`: Pint, PHPStan (`base.neon`, plus `laravel.neon` and the opt-in `strict.neon`), Semgrep rules, and the Rector preset class `Pollora\Harness\Rector\Preset`.
- **ARCH-02 as a PHPStan rule**: code in a `Domain` namespace calls no global WordPress function (`pollora.noWordPressCallInDomain`).
- **Architecture tests** registered by `tests/Arch.php` from the layers found under the PSR-4 roots (`Domain`, `Application`, `Infrastructure`/`Adapter`, `UI`). The *Tests and architecture* gate runs `vendor/bin/pest`, which only runs the suites of `phpunit.xml`: when `tests/Arch.php` exists and no suite holds it, the gate also runs `vendor/bin/pest tests/Arch.php` and says so, so the rules cannot be skipped. Add `<testsuite name="Architecture"><file>tests/Arch.php</file></testsuite>` to `phpunit.xml` to run it with the others.
- **A layer shared with an installed package is judged on the repository's own classes.** Pest's architecture engine resolves a namespace through the PSR-4 prefixes of Composer's class loader, `vendor/` included. When a package under `vendor/` maps a namespace that starts a layer of the repository (`pollora/media` adds `Domain\Exceptions\*` to `Pollora\Media\…` of the framework), asking the engine about that namespace would load the package's classes too, and the engine fails describing them (`Typed property … ::$path must not be accessed before initialization`). `tests/Arch.php` reads `vendor/composer/autoload_psr4.php`; for such a namespace (ARCH-01, ARCH-03, CONV-03, CONV-05, CONV-07) it gives the engine the explicit list of the classes found in the repository's own PSR-4 directories instead of the namespace (a class name is resolved to its own file only, so nothing is read from `vendor/`). The test keeps its name and runs. Two cases stay out, and say so: a class that the package also has **under the same name** cannot be told apart from the package's (a skipped test `… (classes shared with a package)` lists them, reason `not evaluated: <rule> — N class(es) of <namespace> also exist under the same name in an installed package …`), and a namespace left with no class to judge is registered as skipped with the reason. A path repository (a symbolic link under `vendor/`) counts as installed.
- **CONV-02, observed: a WordPress registration is not described by a raw array in the public API.** Read with `nikic/php-parser` from the PSR-4 files, so no autoloader is needed. *Reported*, by two tests that complement each other: (1) a public method or global function with a parameter that can hold an array (typed `array`, nullable, in a union, or untyped) that flows, inside the method, into the arguments that describe a registration: `$args` of `register_post_type()`, `register_taxonomy()`, `register_block_type()`, `register_block_type_from_metadata()`, `register_rest_route()`, or any argument of `add_menu_page()` / `add_submenu_page()`; the flow follows the parameter, its elements, `array_merge($defaults, $args)`, `$args + $defaults`, the local variables assigned from them and closures (`add_action('init', function () use ($args) {…})`); (2) a public method, interface methods included, named `register…`, `create…`, `define…` or `add…`, about a post type, taxonomy, block, route or menu (the namespace, the class or the method says `PostType`, `Taxonomy`, `Block`, `Route` or `Menu`), with a parameter named `$args`, `$arguments`, `$config`, `$options` or `$settings` that can hold an array: this one catches a contract or a service that hands the array to a registry, which the flow cannot see. *Not reported*: the infrastructure layer (a class under an `Infrastructure` or `Adapter` namespace), where WordPress is called and the array is unavoidable: the rule asks that a typed object build it there; non-public methods; a class or method marked `@internal`; a literal array written inside the method (its signature takes no array); a parameter typed as an object. *Cannot be seen* (accepted false negatives): an array stored in a property and registered by another method, a registration through a wrapper (`$this->wp->registerPostType($args)`), a signature whose name or parameter name is none of the above, and the files of `autoload.files` (only PSR-4 classes are read). A file that does not parse is listed (`could not be parsed: not judged`), never skipped silently. The result is `incomplete` with the list while the rule is `observed`; it is not in `allow_rules` yet.
- **Architecture rules that are observed.** `CONV-08` (a class is `final` unless it is an extension point: an abstract class, a class named `…Builder`, a class that uses `Macroable`, or a class that another class of the repository extends), `CONV-09` (a facade is a thin front: its only method is `getFacadeAccessor()`, which returns `Service::class` or a binding name, and a `Service::class` of the repository is an interface, not a concrete class) and `CONV-12` (the tests of the repository, found under `tests/` and its `autoload-dev` roots without the `Fixtures` and `Stubs` directories, are `it()`/`test()` functions: a concrete class that extends a `…TestCase` and has a test method is reported; a test marked `#[RunInSeparateProcess]` or `#[RunTestsInSeparateProcesses]` (or the matching annotations) may stay a class, and so may a PHPStan rule test, which can only be one) read the files of the repository from their tokens, so they need no autoloader. A rule whose status in the catalogue (`resources/rules.php`) is `observed` never fails the *Tests and architecture* step: its test is reported as **incomplete** (`…` in Pest's output) with `observed, not blocking: <rule> — N problem(s)` and the list, and passes when there is nothing to report. Moving the rule to `enforced` in the catalogue turns the same test into a failure; the mechanism is generic (`Architecture::observed()`), so the next rule that is read from files is added the same way. A rule with nothing to read (no class under the PSR-4 roots, no test file) is registered as skipped with `not evaluated: <rule> — <reason>`, never as a pass. They can be listed under `allow_rules` (`RuleExemption::EXEMPTIBLE`) before they are promoted; see [Promoting a rule](#promoting-a-rule).
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
      observed: '' # gates reported but never blocking: secrets, style, security, audit
    permissions:
      contents: read
```

It runs the gates that need nothing but the source: the secrets in the history (SEC-08), the code style (Pint, the template's own `pint.json` or the Laravel preset), the security patterns (SEC-01, SEC-03, SEC-04, SEC-05, CONV-10, with the rules of the version the workflow is pinned at) and the dependency audit of a committed `composer.lock` (SEC-09). PHPStan, Rector, the dependency analysis, the tests matrix, the API contract and the downstream run need a package: a template keeps its own tests in its own workflow.

A template holds placeholders the scaffolder fills in (`%theme_namespace%`, `%plugin_name%`). They are not PHP, so Pint would fail to parse the file: it runs on a copy where each one is an identifier. `observed` lists the gates a template reports without blocking, the way `.harness.json` does for a package: a theme with hundreds of `{!! !!}` it has not yet been through is `observed: security` until it has.

## Integration tests against a real WordPress

Pollora packages test against Brain Monkey, which doubles WordPress. That is fast, and it proves the logic, but a defect that only a real WordPress shows passes the CI: `register_post_meta()` called before WordPress is loaded was found by the framework's downstream job, not by the package's own tests. The reusable workflow has an **optional** job for that. It is off by default: while the input is empty the job is skipped, nothing starts, and no other job, `needs` or required status changes.

### Turning it on

```yaml
jobs:
  harness:
    uses: Pollora/harness/.github/workflows/php-package.yml@main
    with:
      wordpress-integration: tests/Integration # the suite; empty (default) = off
      wordpress-version: latest # latest (default), nightly, 6.9, 6.9.1
      wordpress-plugins: action-scheduler # optional, wordpress.org slugs, comma-separated
    permissions:
      contents: read
```

The job is named `WordPress integration PHP <version>` (one per version of `php-versions`, 8.3 and 8.4 by default). It installs WordPress with WP-CLI under `$RUNNER_TEMP/wordpress` (outside the repository, so no linter reads it), against a MySQL 8.0 service container (digest-pinned), installs the plugins asked for, then exports `WP_PATH` and `WP_CORE_DIR` (the directory holding `wp-load.php`) and `WP_TESTS_DB_HOST`, `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD` to the tests. `WP_DEBUG` is on and cron is off. It then starts the suite, in this order: the `test:integration` script of `composer.json` when there is one (the repository owns the command, as `hook` does with `composer test:wordpress`); otherwise `vendor/bin/pest -c phpunit.integration.xml` when that file exists; otherwise `vendor/bin/pest <wordpress-integration>`. The inputs are validated (a path inside the repository, a version, plugin slugs) before anything runs. Like the *Tests* job it only reports in observe mode and blocks in enforce mode; no other job needs it, so it is a status to require when the repository wants it.

### The bootstrap and a first test

`stubs/wordpress/` holds a bootstrap, a PHPUnit configuration and a test that proves a real WordPress is loaded. Copy them:

```bash
mkdir -p tests/Integration
cp vendor/pollora/harness/stubs/wordpress/bootstrap.php.stub tests/Integration/bootstrap.php
cp vendor/pollora/harness/stubs/wordpress/WordPressLoadedTest.php.stub tests/Integration/WordPressLoadedTest.php
cp vendor/pollora/harness/stubs/wordpress/phpunit.integration.xml.stub phpunit.integration.xml
```

The bootstrap loads Composer's autoloader, then `wp-load.php` from `WP_PATH`, and stops with a clear message when it is missing. `phpunit.integration.xml` keeps the suite apart from `phpunit.xml`: **exclude `tests/Integration` from the suites of `phpunit.xml`**, otherwise the unit run would load WordPress too. The example test checks `function_exists('register_post_meta')`, `get_bloginfo()`, the database, a real hook and a real meta registration; if the bootstrap did not load WordPress, or a Brain Monkey stub were standing in, it fails. Locally: install WordPress anywhere (WP-CLI, SQLite or MySQL) and run `WP_PATH=/path/to/wordpress vendor/bin/pest -c phpunit.integration.xml`.

**Keep the suite out of the release archive.** `phpunit.integration.xml` sits at the repository root, so the *Distribution archive* step (QA-06) reports it as a development file that would travel in the archive Composer downloads. Run `vendor/bin/pollora-qa archive --fix` once: it writes the missing `export-ignore` line (the `tests/` folder is already covered by `/tests export-ignore`). Found by the first pilot of this job, on `Pollora/hook`.

### What it costs, and when to turn it on

Expect roughly 2 to 3 minutes added per PHP version before the tests themselves (MySQL start, WordPress download and install, Composer), 30 to 60 seconds less once the caches are warm; the jobs run in parallel with the others, so a pull request waits only if this job is the slowest. These figures are estimates: they have not been measured on a pilot repository yet. WordPress archives and plugin archives are cached (restored on every run, written only by runs that are not pull requests, like Composer's cache), and `latest` is resolved to a version number first so the cache key is exact.

Turn it on for a repository whose code calls WordPress functions, hooks or the database in ways a double cannot reproduce: hook registration order, `register_*`, queries, options, cron, REST routes, anything that depends on WordPress being loaded. Leave it off for pure-PHP packages with no WordPress call (the ones that already pass on doubles prove nothing more here), and keep the suite small: what a double already proves belongs in the unit suite. MySQL 8.0 is used because it is what hosts run and what `hook` already tests on; it is past its upstream support, so the image will move to 8.4 once a pilot shows the authentication plugin of the client works with it.

## Reliability and security of the workflows

`php-package.yml`, `template.yml` and the harness's own `ci.yml` follow the same rules. Their contract does not change: same inputs, same job names (they are the status contexts the repositories require).

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
5. **Raise the version.** Add the entry under `[Unreleased]` in `CHANGELOG.md` and release a new version; a repository that stays on the previous tag is not affected, and each repository moves to it when it is ready.

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

The **Performance budgets** gate (PERF-01 and PERF-07) reads files; it never starts a site. The framework measures the SQL queries of each type of page and the boot, cold discovery and thumbnail timings in its own browser tests (`tests/e2e`); the gate checks that those measures are still declared, still wired and never loosened. It is `observed`: it reports, and only blocks once it has been measured on every repository and promoted.

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

## Rules

`vendor/bin/pollora-qa rules` prints the catalogue, from `resources/rules.php`. Each rule is `enforced` (fails the gate), `observed` (reported only) or `planned` (not checked by this version yet).

An exception to a rule is written on the line it concerns, with its reason: `// @phpstan-ignore <identifier> (reason)` or `// nosemgrep -- reason`. Nothing is silenced without a trace.

## Status

The harness no longer only measures: every repository that adopted it runs the gates in `enforce` mode, with a ratchet that only moves up (line coverage, PHPStan level and baseline, mutation score).

| | Rules | Which |
| --- | --- | --- |
| Enforced | 32 | style, PHPStan, architecture tests, dependency policy, Semgrep patterns (SEC-01 to SEC-07, CONV-10, and ARCH-05 on the framework), the names of hooks and commands (CONV-04, CONV-06), the downstream job (QA-03, which keeps the framework green), secrets, `composer audit`, the ratchet, the public surface, backward compatibility, the distribution archive, the warm-cache and query-count tests of discovery and Colt (PERF-02, PERF-03, which run in those packages' own suites) |
| Observed | 7 | CONV-02 (no raw registration arrays), CONV-08 (final classes), CONV-09 (thin facades), CONV-11 (native types), CONV-12 (Pest functions), PERF-01 (query budgets), PERF-07 (nightly measures) |
| Planned | 1 | CONV-01 |

**Why CONV-01 stays `planned`.** The rule ("the common case needs no configuration") was read four ways over the 45 repositories, with `nikic/php-parser`, and none is precise enough to report without noise. (1) A public static factory, or a public method of a `*Builder` / `*Factory` / `*Registry` / `*Registrar` / `*Manager`, with 4 required parameters (3 when one is a required `bool`), exceptions, interfaces, callbacks, `@internal`, Infrastructure, Console and Providers left out: 22 reports in 8 repositories, about 1 s; 5 were real (`Ability::create()` and `AbilityDefinition::reading()` / `writing()` with 7 required arguments, `Registry::table()` / `variables()`), 17 were not (12 view helpers of an admin screen, internal factories of the harness, a three-boolean predicate). Without the admin screens and from 5 required parameters: 6 reports, 3 real. (2) A public constructor with 3 or more required scalars: 55 reports, nearly all value objects, events and DTOs, whose fields are required by nature. (3) A required `bool` next to another required parameter: 10 reports, 3 real (`put($path, $contents, $dryRun)`), the others WordPress callbacks and setters. (4) Any public method with 4 or more required parameters: 62 outside constructors, about half of them WordPress hook callbacks or signatures imposed by a contract. Deciding that a parameter has an "obvious default" is a judgment on meaning, not on syntax, and the few real cases (6 to 10 in 45 repositories) are too few to show a false-positive rate under 10 %. The rule stays with its present tool: review, and the documentation examples run as tests.

`pollora-qa rules` prints the catalogue. Done since the first phase: the mutation score (`check --mutation`, see [Mutation score and the ratchet](#mutation-score-and-the-ratchet)) and the blocking Semgrep rules. Integration tests against a real WordPress are an opt-in job (see [Integration tests against a real WordPress](#integration-tests-against-a-real-wordpress)). Still to come: adapter contract tests. The performance budgets are checked from their files (see [Performance budgets](#performance-budgets)); the measuring itself stays in the repository that owns a site.

## Contributing

Contributions are welcome: see the [contributing guide](https://github.com/Pollora/.github/blob/main/CONTRIBUTING.md). Report security issues privately, as described in the [security policy](https://github.com/Pollora/.github/blob/main/SECURITY.md).

## License

Pollora Harness is open-source software licensed under the [MIT license](LICENSE). © [RuBee group](https://rubee.group)
