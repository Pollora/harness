# Changelog

## [Unreleased]

### Added

- **A template can carry an optional `.harness.json`, read by `template.yml` (#107).** The skeleton and the themes have no file, so the type coverage floor of CONV-11 (measured: skeleton 88.3 %, theme-apiary 87.3 %) could not be recorded and a drop went unseen. `template.yml` gains a `Ratchet (QA-01)` job, last in the file, that looks for `.harness.json` or `.github/harness.json` (a directory the release archives leave out) and, only when one exists, checks the harness out, installs nikic/php-parser alone (no `composer install` of the template, no autoloader) and runs the new `pollora-qa check --template`: the ratchet of the file, with the `mode` input of the workflow deciding whether a drop blocks (`enforce`) or reports (`observe`), and `observed: ratchet` reporting it. The profile may be left out of the file (it is then `template`; `project` is accepted, any other is refused), a bad value or key names the file. Compatibility: a template without the file is checked exactly as before (the new job passes at once with a notice, no other job, name, `needs`, `if` or input changes; the new job is skipped steps only). New: `Plan::template()`, `HarnessConfig::loadForTemplate()`, `loadAny()`, `locateForTemplate()`, `TEMPLATE_FILE`, and `--config=FILE` on `check` (with `--template`), `ratchet` and `init`. `ratchet --update` records in that file (never adds `mode`, keeps unknown keys), and `init --profile=template` now writes only that file with the type coverage floor measured now, rounded down to a tenth, instead of the package scaffolding a template could not use; a package profile is unchanged. See *A .harness.json for a template* in the README.
- **`allow_rules` exempts some classes of a rule, not only the whole rule (`classes`).** CONV-08 has hundreds of open classes in 20 repositories, a few of which are real extension points: exempting the rule would stop judging all of them. An entry may now carry `"classes": ["Acme\\Models\\*", "Acme\\Foo"]` (a fully qualified class name or a namespace prefix ending in `\\*`, nothing else) for CONV-02, CONV-08 and CONV-09. The classes are removed from the problems of the rule and named (`exempted: CONV-08 — <reason> — N classes: …`, in a skipped test and in the notes of the *Architecture* and *Tests and architecture* gates); a pattern that matches no class, or none that the rule reports, is a `stale exemption` warning (an incomplete test, never a failure); an exemption of an `observed` rule stays declared, not applied. Several entries may name one rule; an entry limited to classes and a whole-rule entry for the same rule make the file invalid (`InvalidConfig::ruleExemptionConflict`), as do a bad pattern, an empty list, or `classes` on another rule. Compatibility: `classes` is optional and additive; an entry without it, and every existing `.harness.json`, behave exactly as before (`toArray()` writes no `classes` key). New: `Config\ClassPattern`, `Architecture\ClassExemptions`, `ExemptionEffect`, `Exempted`.
- **A ratchet metric `exempted_classes`: the (rule, class) pairs the per-class exemptions remove, lower is better.** It counts a class only when the rule reports it, so a `final` class under an exempted namespace costs nothing and a new open class raises the figure and fails QA-01 against the recorded ceiling: a namespace prefix cannot widen unnoticed. `exempted_rules` keeps counting whole-rule entries only. Measured only in a repository that has a `classes` entry or already records the key (`unmeasured` otherwise), so an existing `.harness.json` is unchanged; `ratchet --update` records and lowers it, `--metric=exempted_classes:N` works.
- **CONV-02 can be exempted (`allow_rules`).** It joins `RuleExemption::EXEMPTIBLE` and `Plan::ARCHITECTURE_RULES` (the test that keeps them equal still holds); its status stays `observed`.
- **`promote-report` measures many repositories in one run.** `pollora-qa promote-report <path>...` or `--root=<dir> [--only=a,b]` prints a rule x repository matrix (text) or, with `--json`, `{repositories, matrix}`: for each repository the rows of the single report, its static `type_coverage` and the `floor` to record in `ratchet.type_coverage` (rounded down to 0.1 point so that measurement noise cannot fail the ratchet), and one `{rule, repository, verdict, count}` cell per rule. A directory without `composer.json` is `not-applicable`; an unreadable directory or an invalid `.harness.json` is named and gives status 1 (never counted clean). Without argument the report of the current repository is unchanged. The promotion study of the 45 repositories is now replayable without an agent.
- **Integration tests against a real WordPress: an optional `WordPress integration` job in `php-package.yml`.** Three new optional inputs, all off or neutral by default: `wordpress-integration` (the suite directory, for example `tests/Integration`; empty, the default, skips the job), `wordpress-version` (`latest`, `nightly` or a version number) and `wordpress-plugins` (wordpress.org slugs). The job, named `WordPress integration PHP <version>`, installs WordPress with WP-CLI against a digest-pinned MySQL 8.0 service container, caches the downloads, and starts the `test:integration` script, `phpunit.integration.xml` or the directory, on PHP 8.3 and 8.4, with a 20-minute timeout. It is skipped while the input is empty and no other job depends on it: no job name, `needs`, `if` or input of the existing jobs changes. `stubs/wordpress/` holds the bootstrap, the PHPUnit configuration and a test that proves a real WordPress is loaded; the README explains the cost and when to turn it on.

### Changed

- **CONV-09, PERF-01 and PERF-07 are `enforced`: they block in `enforce` mode for every repository that raises the harness to the next version.** Measured with `promote-report --root` over the 36 directories of the ecosystem that have a `composer.json` (read only, the `git status` of the 46 checkouts identical before and after): no `would-block` anywhere, `CONV-09 clean` in 34 repositories (1 not applicable), `PERF-01` and `PERF-07` `clean` in the framework, the only repository that declares budgets, and `not applicable` in the 35 others. The framework's four facades (`Option::forget()`, `PostType`, `Taxonomy`, `WordPressError`) were fixed in its pull request #670, and `develop` reads `CONV-09 clean`. CONV-09 passes from the incomplete (observed) block of `tests/Arch.php` to the blocking one with the same message as the other rules, and `allow_rules` exempts it whole or, with `classes`, facade by facade. The *Performance budgets* step leaves `Plan::OBSERVED_BY_DEFAULT`: it fails like the other steps in `enforce` mode (a budget that went up, an invalid budgets file, nightly measures that are not wired), and stays `skipped` with its reason, never a failure, in a repository that declares neither. The number of steps of `check` does not change (13); a repository that stays on 0.1.34 is not affected. CONV-02, CONV-08, CONV-11 and CONV-12 stay `observed`; the catalogue now counts 35 enforced, 4 observed and 1 planned rule.
- **`promote-report` takes the per-class exemptions into account.** The text says `CONV-08  would block (15 found, 12 exempted by allow_rules in 4 classes: 3 remaining)` (or `clean` when nothing remains) and lists the stale patterns. In the JSON, `count` is what remains after the exemptions (unchanged without any), and each row gains `found`, `exempted_problems`, `exempted_classes` and `stale`.

### Fixed

- **Per-class exemptions no longer need `ext-mbstring`.** The label of the test that names an exemption was cut with `mb_strimwidth`, which the dependency analysis (ARCH-07) reported as a shadow dependency: the harness would have required an extension from every repository it is installed in. `Support\Text::shorten()` does the same with PCRE only (characters, never half a multi-byte character, invalid UTF-8 returned as it is).
- **The WordPress integration template passes PHPStan, and the README says to export-ignore the suite.** The first pilot of the job (`Pollora/hook`, PHP 8.3 and 8.4 both green in 37 s and 46 s) showed two defects of the template, not of the job: `->not->toBe('')` in `WordPressLoadedTest.php.stub` fails PHPStan level 5 on a repository that analyses its tests (*Access to an undefined property Pest\Mixins\Expectation::$not*), now positive expectations; and `phpunit.integration.xml` at the root is a development file that the *Distribution archive* step (QA-06) reports, so the README now tells to run `pollora-qa archive --fix`.

- **The dependency audits try again when the network fails, and never when they have found something (SEC-09).** `composer audit` failed twice in an hour on `curl error 28 while downloading https://packagist.org/api/security-advisories/: Connection timed out`, an incident of Packagist unrelated to the code. The *Dependency audit* step (`Check\ComposerAudit`) now attempts the call 3 times in all, 5 s then 15 s apart, only when the output is a transport failure (timeout, DNS, refused or reset connection, TLS handshake, HTTP 5xx or 429: `Support\TransportErrors`); advisories found, a lock file error or any other output fail at once, with no second attempt. An advisory API still unreachable after the attempts FAILS the step (never a pass) with `advisory API unreachable after 3 attempts`, a text that says it is a network failure and not a vulnerability. The npm audit (`Npm\NpmAudit`) does the same for a registry error (`EAI_AGAIN`, `ETIMEDOUT`, `ECONNRESET`, HTTP 5xx and so on); its other failures keep failing at once. New command `pollora-qa composer-audit [--locked]`, the same code, which the reusable workflow `template.yml` now runs (it checks the harness out as its npm audit job does); the job names and the inputs are unchanged. The other network calls of the workflows (the gitleaks image, `pipx install semgrep`) are not attempted again.
- **`init` run again no longer erases settings.** It rebuilt the configuration without `mutation`, `npm_audit`, `services` and `slow_step_share`. It now keeps every key it does not decide, and `HarnessConfig::save()` (used by `init` and `ratchet --update`) carries over a top-level key this version does not know, as it was. `HarnessConfig::withMutation()` also dropped `performance`.
- **`pollora-qa help` aligns the commands** whatever the length of their names: the column is as wide as the longest (`promote-report` overflowed the fixed 9 characters).
- README: the *Status* section says the performance budgets are delivered and that integration tests against a real WordPress and adapter contract tests remain.
- `Layers::psr4()` no longer reads a missing `composer.json` through a suppressed warning (a directory without one is simply empty).

### Documentation

- **CONV-01 stays `planned`, and the README says why.** Four readings of "the common case needs no configuration" were measured over the 45 repositories (long required parameter lists on public factories and registries, constructors with many scalars, required boolean flags, any public method with 4 or more required parameters): the best one reports 22 methods, 5 real, so no code was added. The figures are in the README, under *Status*.

## [0.1.34] - 2026-11-21

### Added

- **`promote-report` reads PERF-01 and PERF-07.** It uses the same static assessment as the `Performance budgets` step (`PerformanceCheck::assess()`, which `run()` now shares): `would-block` with the problems of the budgets file or of the nightly measures, `clean`, or `not-applicable` when the repository declares neither. Without it the two rules showed as `no-probe` once PERF-01 and PERF-07 became observed.

- **PERF-01 and PERF-07 are `observed`: a *Performance budgets* step reads the query budgets and the nightly measures of a repository, without running a site.** PERF-01: when the repository has a budgets file (`tests/e2e/budgets.json` by default, the framework's `{ "pages": { "home": { "wordpress": 24, "laravel": 0 } } }`), the step validates its shape (whole numbers or `null`), lists the `null` budgets as measured-only and applies a ratchet: a budget that went up, became `null` or was removed since the base commit (`HEAD^1`, or `check --budgets-base=REF`) fails; a base that is not in the checkout, a file new since the base or an unreadable base file give `not compared with <base>: <why>`, never a pass that did not compare. PERF-07: when the repository has the nightly measuring spec (`tests/e2e/specs/perf-metrics.spec.ts`) or declares `performance.nightly`, the step checks that the measuring file and the comparing script exist, that a workflow has an `on: schedule` with a `cron` and runs both, and that the comparison's `--tolerance` is at most 0.1. A repository that declares neither gets `skipped` with the reason. The new `performance` key of `.harness.json` (`budgets`, `nightly.measure`, `nightly.compare`, `nightly.workflow`, all optional, paths inside the repository) is read, written by `ratchet --update` and kept by `init`. The step is observed by default (`Plan::OBSERVED_BY_DEFAULT`), so `check` has 13 steps instead of 12; the catalogue now counts 32 enforced, 6 observed and 2 planned rules. The reusable workflow `php-package.yml` always checks out two commits (`fetch-depth: 2`; it was 2 only for a limited mutation scope) so that the budgets can be compared.
- **CONV-02 is `observed`: a WordPress registration is not described by a raw array in the public API.** `Architecture\RawRegistrationArrays` reads the PSR-4 files with `nikic/php-parser` (no autoloader) and reports (1) a public method or global function whose array parameter flows into `register_post_type()`, `register_taxonomy()`, `register_block_type()`, `register_block_type_from_metadata()`, `register_rest_route()` (their `$args`) or `add_menu_page()` / `add_submenu_page()`, through array operations, local variables and closures, and (2) a public method (interface methods too) named `register…`, `create…`, `define…` or `add…` about a post type, taxonomy, block, route or menu, with an `$args`, `$arguments`, `$config`, `$options` or `$settings` parameter that can hold an array. The infrastructure layer (`Infrastructure`, `Adapter` namespaces), non-public methods, `@internal` code, literal arrays built inside a method and object-typed parameters are not reported; the rule's limits (array stored then registered elsewhere, registration through a wrapper) are in the README. A file that does not parse is listed, never skipped. Registered by `tests/Arch.php` like CONV-08/09/12 (incomplete with the list, `not evaluated` when there is no class); the catalogue now counts 32 enforced, 5 observed and 3 planned rules. Measured over the 45 repositories (36 have PHP): 10 reports in 2 repositories (framework 8, entity 2), about 1.0 s in total, 0.21 s for the framework.
- **Mutation on pull requests: only what the pull request changes.** `check` takes `--mutation-scope=all|changed|added`, which wins over `mutation.scope` of `.harness.json` for that run (an unknown value, or the option without value, fails before any gate runs). A limited scope is held to `mutation.min`, or to `MutationSettings::PULL_REQUEST_MINIMUM` (50 %) when the file sets none. The reusable workflow `php-package.yml` has a new optional input `mutation-on-pull-request` (`changed` by default, `all` for the old behaviour): on a `pull_request` event a repository without `mutation.scope` runs `--mutation-scope=changed --mutation-base=HEAD^1` on the merge commit (checkout depth 2); a checkout that is not a merge commit falls back to the whole repository with a warning instead of counting from the wrong base; every other event mutates everything, so the `msi` ratchet is still measured on the main branch. A pull request without a PHP source file passes with "nothing to mutate". The *Ratchet* step says `msi` was not measured on purpose in a limited run. No job name, `needs` or `if` changes. Measured cost: the mutation is 80 to 90 % of the quality job (101 jobs, 33 repositories, 20 November). See *Mutation on pull requests* in the README for the trade-off (the whole score is no longer measured on a pull request).
- **CONV-08, CONV-09 and CONV-12 are `observed`: three architecture rules read from the files, which report and never block.** CONV-08 (a class is `final` unless it is an extension point: abstract, a builder named `…Builder`, a class that uses `Macroable`, or a parent that another class of the repository extends), CONV-09 (a facade is a thin front: only `getFacadeAccessor()`, returning `Service::class` or a binding name; a `Service::class` of the repository that is a concrete class is reported, a contract is an interface) and CONV-12 (no PHPUnit test class: a concrete class extending a `…TestCase` with a test method is reported; `#[RunInSeparateProcess]` / `#[RunTestsInSeparateProcesses]` and the PHPStan rule tests are the documented exceptions; the `Fixtures` and `Stubs` directories are not read). They are read from the tokens (`Architecture\SourceTypes`, `FinalClasses`, `FacadeClasses`, `TestClasses`), without an autoloader. A rule that the catalogue lists as `observed` is registered by `tests/Arch.php` as a test that is marked incomplete, with `observed, not blocking: <rule> — N problem(s)` and the list, when it finds something (the step stays green) and passes when it finds nothing; `enforced` in the catalogue makes the same test fail. Measured over the 45 repositories of the ecosystem (a script running the three analyses on each repository's files, without `vendor/`): CONV-08 reports 671 classes in 20 repositories (framework 400, attributes 82, metabox 54, colt 23, skeleton 19, woocommerce 16, nectar 16, query 12, assets 12, entity 11), CONV-09 4 facades in the framework, CONV-12 13 test classes (meilifacets 11, skeleton 2); about 1.1 s in total over the 45 repositories, 0.33 s for the framework. A rule with nothing to read (no class, no test file) is skipped with `not evaluated: <rule> — <reason>`. The catalogue now counts 32 enforced, 4 observed and 4 planned rules.
- **`type_coverage` is measured (CONV-11): `check` and `ratchet` count the declarations that carry a native type.** `Metrics\TypeCoverage` reads the PHP of the repository with `nikic/php-parser` (now a requirement, `^5.6`: it was only there through Pest's dev dependencies, and PHPStan and Rector ship it inside their phars), so it needs neither Pest's type-coverage plugin (installed in two repositories out of forty-five) nor an autoloader. It counts the return type of every function and method (constructors and destructors excepted), every parameter (promoted ones once) and every property; `mixed` is a type; closures and arrow functions are out; `%placeholder%` markers of theme templates are neutralised; the parameters of the methods that override an untyped method of WordPress (`Walker*`, `WP_Widget`; the parent must be the global class, `\Walker_Nav_Menu`) are left out and reported, because a child cannot narrow them. The files read are the `psr-4`, `psr-0`, `classmap` and `files` entries of `autoload` plus `src` and `app`, minus `vendor`, `node_modules`, `tests` and Blade. The *Ratchet* step prints the percentage, the share of returns, parameters and properties, the five files that concentrate the gap and any file that does not parse (never ignored silently); a repository that records `type_coverage` in its `ratchet` can no longer lower it, and `ratchet --update` records it. Measured over the 45 repositories: 97.19 % (18 316 of 18 846 declarations) in 1.5 s, 0.46 s for the framework. CONV-11 stays `observed` until the floors are recorded.
- **`pollora-qa promote-report [--json]`: what would block if each observed rule became enforced.** For every `observed` rule of the catalogue, in the current repository: the number of problems and the first five, `clean`, `not applicable` (nothing to read) or `no probe` (an observed rule it cannot measure is listed, never left out). CONV-08, CONV-09 and CONV-12 run the same analyses as their Pest tests, CONV-11 compares the static type count with the recorded `type_coverage` floor. Read only, no tool run, always exit 0; works without `.harness.json`. README: *Promoting a rule*.
- **CONV-08, CONV-09 and CONV-12 can be exempted (`allow_rules`) before they are promoted.** They join `RuleExemption::EXEMPTIBLE` and the rules named by the *Architecture* and *Tests and architecture* steps (`Plan::ARCHITECTURE_RULES`). While a rule is `observed` its exemption is accepted, counted by the `exempted_rules` ceiling and shown (`declared, not applied while the rule is observed`) but not applied: the rule keeps reporting, and the step does not list it as `exempted`. Once the catalogue says `enforced`, the same entry skips the rule with its reason.

### Changed

- **A `ratchet` value that cannot be compared makes `.harness.json` invalid.** `coverage`, `type_coverage` and `msi` must be numbers from 0 to 100, `phpstan_level` an integer from 0 to 10 or `"max"`, `phpstan_baseline` and `exempted_rules` whole numbers from 0. Until now `"type_coverage": "95"` or `120` was read as "never measured" and silently never compared (`InvalidConfig::ratchetValue`). None of the 45 repositories holds such a value.
- **A layer shared with an installed package is now judged, on the classes of the repository only, instead of not evaluated.** The 0.1.32 compromise skipped ARCH-01, ARCH-03, CONV-03, CONV-05 and CONV-07 on a namespace that a package under `vendor/` also maps (the framework lost six CONV-07 tests with `pollora/media` 0.9). Those rules now receive the explicit list of the classes found in the repository's own PSR-4 directories instead of the namespace: the engine of Pest resolves a class name to its own file only, so the package's classes are never read and `ObjectDescriptionBase::$path` is never reached. Only a class that the package also has under the same name is left out, with a skipped test `… (classes shared with a package)` that names it, and a namespace with no class to judge is skipped with the reason. The test names are unchanged. `ForeignNamespaces` keeps the directories of each foreign prefix (`directories`) and tells a shadowed class (`shadows()`), `Layers::classesIn()` lists the classes of a namespace.
- The job summary table of `check` has one more column, *Time*, between *Rules* and *Result*.

### Fixed

- **SEC-06 no longer reports a bare constant.** `$token[0] === T_STRING`, a lexer token compared with its tokenizer constant, is a type check, not a secret comparison; it joins the literals, `null`, numbers and `Cls::NAME` constants that were already left out. Found by the first run of the rule as a blocking one, on the harness's own code (4 findings, `src/Architecture/SourceTypes.php`); the fixtures that must be detected still are (43 detections, unchanged), a negative fixture was added, and the self scan of `src/` goes from 4 findings to none.

- **Fewer `HTTP/2 429` from `codeload.github.com` during `composer install`.** The Composer steps are authenticated with the job's token (`COMPOSER_AUTH`, `github-oauth`), the Composer download cache is restored (and saved by non-pull-request runs), and a pull request's older run is cancelled when a new push arrives. Every job has a `timeout-minutes`.

### Security

- **The workflows pin what they run.** `php-package.yml`, `template.yml` and `ci.yml` reference `actions/checkout`, `shivammathur/setup-php`, `actions/upload-artifact`, `actions/setup-node` and the new `actions/cache` by the full commit SHA of their tag (tag in a comment), install `semgrep==1.186.0` and run `ghcr.io/gitleaks/gitleaks:v8.30.1` by digest instead of `latest`. The one `${{ }}` of a step output inside a `run:` (the downstream job's package name) goes through `env:`. zizmor 1.32.0: 34 high and 1 informational findings before, none after. Inputs, outputs and job names are unchanged. See *Reliability and security of the workflows* in the README.

## [0.1.33] - 2026-11-20

### Added

- **`services` in `.harness.json`: a repository can ask the CI for a Redis.** `{ "services": ["redis"] }` makes the reusable workflow `php-package.yml` start a `redis:7-alpine` on `127.0.0.1:6379` and install the `redis` and `igbinary` PHP extensions in the *Quality gates* job (where coverage and the mutation score are measured) and in every *Tests* job, so a repository whose tests need a real Redis runs them instead of skipping. `HarnessConfig` reads and writes the key (so `ratchet --update` keeps it) and rejects a name it does not know (`InvalidConfig::unknownService`); the only known service is `redis`. Prompted by `pollora/object-cache`.

### Changed

- **SEC-02, SEC-06, SEC-07, CONV-04, CONV-06 and QA-03 are `enforced`: they block in `enforce` mode for every repository that raises the harness to 0.1.33.** Measured over 45 repositories of the ecosystem: no Semgrep result for the three security rules (the two `nosemgrep` comments of `ai-visibility` and `mcp-connector` stay valid), no command problem and no hook problem once the false positive below is fixed. The four Semgrep rules of `presets/semgrep/observed.yml` move to `common.yml` and the file is removed; the *Security patterns (observed)* gate is gone (the report has one step less), and *Security patterns* now lists SEC-02, SEC-06 and SEC-07. A naming problem found by the *Public surface* gate (CONV-04, CONV-06) now fails it, where it only added a line to the output before. QA-03 was already blocking in `enforce` mode (the `downstream` job of `php-package.yml`): only the catalogue and a comment said otherwise. CONV-11 stays `observed`; the catalogue now counts 32 enforced, 1 observed and 7 planned rules.

### Fixed

- **SEC-06 no longer reports the key of a `foreach`.** `$key === $signature` inside `foreach ($entries as $signature => $entry)` compares a name taken from an array, not a secret (`schedule`, `CronIndex.php`); the rule `pollora.sec-06.secret-compared-without-hash-equals` leaves it out. A variable that is not that very key (`$entry['signature']`) is still reported.
- **A cache key or an option name written `pollora/…` is no longer read as a hook of the public surface.** `wp_cache_get('pollora/theme_templates', …)` made `SurfaceExtractor` record `hook:pollora/theme_templates`. A string that is the first argument of a function that does not name a hook (the object cache, transients, options and their site and multisite variants, listed in `SurfaceExtractor::NOT_HOOK_CALLS`) is ignored; every other string still counts, `do_action()` and `apply_filters()` or not. The framework must run `vendor/bin/pollora-qa surface --update` once: the entry `hook:pollora/theme_templates` disappears from its `api-surface.json`.

## [0.1.32] - 2026-11-19

### Fixed

- **An architecture rule no longer fails when an installed package maps the same namespace as a layer of the repository.** In the framework, CONV-03 (`Pollora\Media\Domain\Exceptions`) failed with `Typed property PHPUnit\Architecture\Elements\ObjectDescriptionBase::$path must not be accessed before initialization` as soon as `pollora/media` 0.9.0 (psr-4 `Pollora\Media\` under `vendor/`) added its own `Domain/Exceptions/*` classes to that namespace: the engine of Pest resolves a namespace through every PSR-4 prefix of Composer's class loader, `vendor/` included, and cannot describe those files. `Architecture::register()` now reads `vendor/composer/autoload_psr4.php` (new `Architecture\ForeignNamespaces`) and registers the rules on such a namespace (ARCH-01, ARCH-03, CONV-03, CONV-05, CONV-07) as skipped tests reading `not evaluated: <rule> — <namespace> is also mapped by an installed package (<prefix>, under vendor/)`, never as a silent failure; the other namespaces keep their rules. The step count of `pollora-qa check` is unchanged.

## [0.1.31] - 2026-11-19

### Added

- **SEC-06 and SEC-07 are `observed`: four Semgrep rules in `presets/semgrep/observed.yml`, in the same *Security patterns (observed)* gate as SEC-02.** `pollora.sec-07.admin-handler-without-capability` reports an `add_action()` / `Action::add()` / `$action->add()` on `admin_post_*` or `wp_ajax_*` whose closure or arrow function never calls `current_user_can()` or `user_can()`; `pollora.sec-07.menu-page-without-capability` reports `add_menu_page()`, `add_submenu_page()`, `add_options_page()` and the other `add_*_page()` functions given an empty or `read` capability. `pollora.sec-06.unanchored-end-of-line` reports a `preg_match()` whose literal pattern ends with `$` without the `D` modifier or `\z` (a `$` accepts a trailing newline, which a path, a slug or a size taken from a request can smuggle past the check) when the subject is `$_GET`/`$_POST`/`$_REQUEST`/`$_SERVER`/`$_COOKIE` or is named like a request value; a pattern with the `m` flag and the `Console` and `Commands` directories are left out. `pollora.sec-06.secret-compared-without-hash-equals` reports `==`, `===`, `!=` and `!==` on a value named `signature`, `hmac`, `token`, `secret`, `api_key`, `private_key`, `secret_key` or `license_key`, and asks for `hash_equals()`; a comparison with a literal, `null`, `[]` or a class constant is left out, and so is a bare `key`, which names array keys far more often than secrets. Measured over the whole tree of the repositories of the ecosystem, they report no SEC-07 violation (every hand-registered handler uses a callable array, which a Semgrep rule cannot follow, and the four read by hand check `manage_options`) and a handful of SEC-06 ones, hence `observed`. The Semgrep fixtures test now accepts several `ruleid:` markers on one line. The three rules enter the catalogue as SEC-06 and SEC-07 (`observed`).

- **SEC-02 is `observed`: a hand-registered AJAX hook is reported.** The Semgrep rule `pollora.sec-02.manual-ajax-hook` (`presets/semgrep/observed.yml`) flags `add_action()`, `Action::add()` and `$action->add()` called with a `wp_ajax_*` or `wp_ajax_nopriv_*` hook (a literal, `"wp_ajax_{$x}"` or `'wp_ajax_' . $x`), which skips the nonce that `pollora/ajax` 1.2.0 checks, and points to `#[Ajax]` / `Ajax::listen()->requireNonce()`. Only `Adapter/Out/WordPress/WordPressAjaxActionRegistrar.php` (the registrar of `pollora/ajax`) is exempt. It runs as its own gate, *Security patterns (observed)*, which never blocks and is observed by default, because the measure over the ecosystem found three hand-registered hooks (`ai-visibility`, `mcp-connector`, the framework's `VersionCheck`): it becomes `enforced` once they go through `pollora/ajax`. The Semgrep fixtures test now copies nested directories, to check the exemption.
- **`npm_audit.allow` tolerates an npm advisory that has no fix, for a limited time (SEC-09).** `.harness.json` takes `{ "npm_audit": { "allow": [ { "advisory": "GHSA-fc5q-39xc-vjpf", "reason": "…", "expires": "2026-12-18" } ] } }`. The *Dependency audit (npm)* gate now reads `npm audit --json`: a high or critical advisory is ignored only when its GHSA identifier is listed and the expiry is not past; an unlisted advisory blocks as before; an expired exception fails the gate (`exception expired on …`); the tolerated advisories are printed with their reason and date and written to the job summary; an exception that matches nothing is reported. A package that depends on the vulnerable one is covered by the same entry. A report npm could not produce (registry unreachable, no JSON) now fails the gate instead of passing. `advisory`, `reason` and `expires` are validated (`InvalidConfig`: GHSA identifier, non-empty reason, calendar date at most 90 days ahead, no duplicate). Prompted by GHSA-fc5q-39xc-vjpf (picomatch <= 4.0.7), which has no patched version.
- **`pollora-qa npm-audit [--config=FILE]`** runs that audit alone. The reusable workflow `template.yml` calls it (it checks the harness out at the workflow's own version), so the `check` step and the templates share one implementation instead of a shell copy; it reads `npm_audit` from `.harness.json` or `.github/harness.json`.

### Changed

- **PERF-02 and PERF-03 are `enforced`, and CONV-02, CONV-08, CONV-09, CONV-12 are reworded.** The warm-cache test of `pollora/discovery` (`WarmCacheReadsTest`, a file-access probe) and the query-count tests of `pollora/colt` (`QueryCounter`) are in those packages' own suites, which block the merge, so the catalogue no longer lists them as `planned`. CONV-08 no longer says public classes stay open: 788 of the 1517 classes were already `final`, so it now says a class is final unless it is an extension point. CONV-09 drops the `fake()` requirement (none of the 18 facades has one, and only 3 have a contract): a new facade resolves a contract. CONV-02 and CONV-12 say what they check. `AGENTS.md` and the README *Status* counts follow (26 enforced, 7 observed, 7 planned).

## [0.1.30] - 2026-11-18

### Fixed

- **The mutation gates fail when Pest cannot write its temporary files, instead of reading 100 %.** Pest writes every mutant under `vendor/pestphp/pest-plugin-mutate/.temp/mutations`; when that directory is missing or read-only, each mutant's process fails, a failed process counts as a killed mutant, and the score reads 100 % whatever the tests assert, with only a PHP warning (`Failed to open stream`) to say so. A test without any assertion scored 100 % that way during the framework's adoption of the gate. `MutationRun::run()` now returns `unwritable: true` and a null score when it sees that warning, does not repeat the run, and both gates (*Mutation testing*, and the changed-files and new-files ones) fail with a message naming the directory.

## [0.1.29] - 2026-11-18

### Added

- **`mutation.scope: "added"` holds only the files a change adds to the minimum.** `{ "scope": "added", "min": 80 }` mutates the source files `git diff --diff-filter=A` lists between the base and `HEAD` and the *Mutation testing (new files)* gate fails below `min`; the files the change only modifies are not mutated (the message counts them). It is for a repository whose existing files score unevenly: the framework's, measured one by one, run from 51 % to 100 % (median 74 %), so a minimum fit for new code would block every change to an old file for mutants it did not write. The reusable workflow treats `added` like `changed` (it runs `check --mutation` and keeps two commits at checkout). `ChangedSources::since()` takes the git diff filter (`ChangedSources::CHANGED` or `ChangedSources::ADDED`).

## [0.1.28] - 2026-11-18

### Added

- **`mutation.scope: "changed"` limits the mutation gate to the files a change touches.** `.harness.json` takes a `mutation` key, `{ "scope": "changed", "min": 80 }`: `check --mutation` mutates only the source files that differ between a base and `HEAD` (`pest --mutate --path=a.php,b.php`) and the new *Mutation testing (changed files)* gate holds their score to `min`. It exists for a repository whose whole mutation run is too long for a pull request (the framework's did not finish in thirteen hours). `min` is a fixed threshold, never recorded, tightened or compared with `ratchet.msi`, because the score of some files says nothing about the files of the next change; the gate blocks in `enforce` mode (it is not observed by default), without `min` it only reports. The default scope, `all`, and `ratchet.msi` behave as before.
- **`check --mutation-base=REF`** sets the commit the changed files are counted from (default `HEAD^1`: the target branch on a pull request's merge commit). A base git cannot resolve fails the gate with the fix instead of skipping it.
- **The reusable workflow handles the new scope.** `php-package.yml` runs `check --mutation` for a repository that declares `mutation.scope: "changed"` (it did so only for `ratchet.msi`), and keeps two commits in the *Quality gates* checkout (`fetch-depth: 2`) so that `HEAD^1` exists.
- `Pollora\Harness\Support\ChangedSources` lists the source files a change touches: those under the autoloaded directories, without tests, `vendor/`, `autoload.files`, deleted files or the `.mutant-*` copies of Pest's mutation bootstrap.

### Fixed

- **A file without a mutant is no longer read as a score of 0 %.** Pest prints `Mutations: 0 tested` and `Score: 0.00%` for an interface or a file with nothing to mutate; `MutationRun::run()` now returns `empty: true` and a null score, and does not repeat the run in a single process. The whole-repository gate says "Pest created no mutation" instead of recording 0.

## [0.1.27] - 2026-11-17

### Fixed

- **SEC-01 and ARCH-05 are enforced, as they already behaved.** The catalogue (`resources/rules.php`) listed the two rules as `observed`, but the *Security patterns* gate runs Semgrep with `--error`, which fails on a finding whatever its severity: a REST route declared without a permission (SEC-01) or a third-party plugin referenced in the framework core (ARCH-05) already failed the build. The catalogue now says `enforced` and both rules have `severity: ERROR`, so the behaviour no longer depends on that option. Measured on 29 repositories before the change: no finding of either rule anywhere, so nothing starts to fail.

### Changed

- The README "Status" section describes the real state (24 rules enforced, 4 observed, 12 planned) instead of "phase 1".

## [0.1.26] - 2026-11-17

### Added

- **The reusable workflow checks the mutation score of a repository that recorded one.** `php-package.yml` reads `ratchet.msi` in `.harness.json` and, when it is there, runs `pollora-qa check --mutation` in the *Quality gates* job; a repository without it is untouched. The *Mutation testing* gate stays observed by default and only the ratchet fails a repository whose score dropped below what it recorded. The README says how to record a floor (a couple of points under the measured score, since the figure moves with the load of the machine) and describes two traps of Pest's runner: a `--filter` regex that can exceed the PCRE limit on heavily covered lines (adding tests can then lower the score), and classes Composer loads from `files` that are never mutated.

## [0.1.25] - 2026-11-16

### Added

- **`check --mutation` runs Pest's mutation testing and feeds the ratchet's `msi`.** The *Mutation testing* gate runs `pest --mutate --everything --covered-only` (no `covers()` needed, only the code the tests reach), reads the score Pest prints and compares it with the recorded `msi`. It runs in parallel and repeats once in a single process when that prints no score, since a suite that shares global state fails in parallel. The gate is observed by default and skipped without `pestphp/pest-plugin-mutate`. Infection's JSON log (`--infection=FILE`) is still read; Infection 0.35 does not find the tests Pest 3 generates, which is why Pest's own runner is used.

## [0.1.24] - 2026-11-16

### Fixed

- **`check --coverage` measures every directory `phpunit.xml` lists in `<source>`, not just the one pcov guesses.** With pcov, pcov instruments a single directory it picks by itself (`src`, `lib` or `app` under the working directory). A repository that lists another directory in `<source>` (`vocabulary/`, `support/`, `cli/`…) (#69) had those lines counted in the total and never instrumented, so a fully tested folder read as 0 % covered: `pollora/attributes` measured 91.07 % and measures 100 % with the same tests. The suite is now started with `-d pcov.directory=<project root>` and `-d pcov.exclude` for `vendor`, `node_modules`, `.git` and `.harness`. A `pcov.directory` the project chose itself (anything but the guessed `src`, `lib`, `app` or the root) is left alone, Xdebug is not affected, and the reported figure can only go up. The recorded `line_coverage` floors do not move on their own: raise them with `ratchet --update`.

## [0.1.23] - 2026-11-05

### Added

- **The summary shows an exempted rule, and the ratchet counts exemptions** (#62). Two limits noted in 0.1.22 are lifted. (a) The summary of `pollora-qa check` no longer lists a gate that skipped a rule of `allow_rules` as a plain pass: the console prints `✔ passed  Tests and architecture … (1 rule exempted: ARCH-08)` (and the same on *Architecture* in the fast plan), the GitHub job summary reads `✅ passed (1 rule exempted: ARCH-08)`, and the step of `.harness/report.json` gains `"exempted": ["ARCH-08"]`; its `status` stays `passed`. (b) A new ratchet metric, `exempted_rules` ("Architecture rules exempted", lower is better, like `phpstan_baseline`), counts the exemptions: more than the recorded ceiling is `regressed` and fails QA-01 in enforce mode, so a new exemption cannot slip in; fewer is `improved`, and `ratchet --update` lowers the ceiling, down to `0`, and never raises it. `--metric=exempted_rules:N` works like the other metrics. Compatibility: the metric is only measured in a repository that lists `allow_rules` or already records the key, so an existing `.harness.json` without it reads `unmeasured` and passes unchanged; in a repository that uses `allow_rules`, the first `ratchet --update` records the count (`Pollora/attributes` goes to `1`), and until then the metric reads `new`, which does not fail. Visible change: the ratchet table of such a repository has an extra row, and an unchanged run no longer hides the exemption in the summary. `allow_commands` is not tracked by the ratchet yet; that is a possible follow-up

## [0.1.22] - 2026-11-04

### Added

- **`allow_rules` in `.harness.json` exempts one architecture rule, with a reason** (#62). `{"rule": "ARCH-08", "reason": "…"}` replaces the `beforeEach` that a repository wrote in `tests/Arch.php` to skip a rule by hand (`Pollora/attributes`, whose published attribute classes cannot become final): `Architecture::register()` reads the list and registers the tests of that rule as skipped, with `exempted: ARCH-08 — <reason>` as the reason, and the *Architecture* and *Tests and architecture* gates print the same line (`(allow_rules)`) before they run, so an exemption is visible before the run. Known limits: the summary table of the gate still lists the rule as passed, and the exemption is not recorded in the ratchet. Every other rule keeps running. `rule` must be one `tests/Arch.php` evaluates (ARCH-01, ARCH-03, ARCH-08, ARCH-09, CONV-03, CONV-05, CONV-07); an unknown rule, a rule checked by another gate, a rule listed twice or an entry without a reason makes the file invalid, with a message naming the entry. `init` and `ratchet --update` keep the list. Nothing changes for a repository that does not use it, and `tests/Arch.php` stays a single line. Documented in the README. The exemption covers a whole rule; a narrower scope (one class or namespace of ARCH-08) is a possible follow-up, not implemented here

## [0.1.21] - 2026-11-04

### Fixed

- **ARCH-09 only checks files that declare a class, interface, trait or enum** (#59). Since 0.1.20 the rule read every `.php` file under the PSR-4 roots, so the Blade templates kept under `src/` in `Pollora/nectar` (`src/Mcp/Prompts/*/*.blade.php`) were reported as classes without `declare(strict_types=1)` and the *Tests and architecture* gate failed. A file is now checked only when its tokens contain a type declaration (`Foo::class`, `new class` and the words in strings or comments do not count); `*.blade.php` files and files without a type, such as helper scripts, are ignored, as they were before 0.1.20. Visible change: a repository that 0.1.20 turned red for such a file turns green again; a class file without strict types is still listed

## [0.1.20] - 2026-11-04

### Fixed

- **The *Tests and architecture* gate runs `tests/Arch.php` even when `phpunit.xml` lists no suite for it** (#53). `vendor/bin/pest` only runs the suites of `phpunit.xml(.dist)`, so a repository whose suites left out `tests/Arch.php` passed the gate while its architecture rules (ARCH-01, ARCH-03, ARCH-08, ARCH-09, CONV-03, CONV-05, CONV-07) were never evaluated. The harness now reads the suites: when `tests/Arch.php` exists and no `<file>` or `<directory>` of a suite runs it (a `<directory>` only counts when its `suffix` matches `Arch.php`, the default being `Test.php`; an `<exclude>` removes it), the gate runs `vendor/bin/pest` and then `vendor/bin/pest tests/Arch.php`, fails if either fails, and says so before it starts. With `--coverage`, the coverage report still comes from the first run. A repository that lists the file in a suite (this one, `Pollora/discovery`) runs it once, as before. Visible change: a repository whose `tests/Arch.php` was failing without anyone seeing it will now see the gate fail; fix the rules, or add the file to a suite (`<testsuite name="Architecture"><file>tests/Arch.php</file></testsuite>`) to run it with the others

- **`pollora-qa init` no longer duplicates `.gitattributes` lines and says which PHPStan includes it removes** (#54). `/.gitattributes export-ignore` was added twice in one run (once from the harness list, once from the files found at the top level), and a line already present under another spelling (`tests/`, `/tests`, `tests`) was added again; each entry is now written once. When `init` drops the `szepeviktor/phpstan-wordpress` or `larastan/larastan` include of `phpstan.neon` because the harness preset already loads it, the output now says so (`removed … from the includes of phpstan.neon: the harness preset already loads it`). Visible change: running `init` on a repository that already has some of these lines adds fewer of them, and a removed include appears in the list of changes

- **ARCH-09 no longer crashes when the root namespace is also mapped by a dependency** (#56). In `Pollora/schedule`, `pest tests/Arch.php` stopped with `Typed property PHPUnit\Architecture\Elements\ObjectDescriptionBase::$path must not be accessed before initialization`: `Pollora\Schedule` is also mapped by `pollora/attributes` (`vocabulary/Schedule`), Pest's `toUseStrictTypes()` resolves a namespace through the whole autoloader, and the vendor classes it brings in have no file to read. Since #53 runs `tests/Arch.php` in the gate, such a repository turned red for a reason unrelated to its code. The rule now reads the files found under the repository's own PSR-4 roots and names each class that lacks `declare(strict_types=1)`, whatever else the autoloader maps under the same namespace. Nothing to change in `tests/Arch.php` (it still holds the one `Architecture::for(...)->register()` line). Visible change: a violation is listed by class name in the failure message instead of by file and line, and `@pest-arch-ignore-line` no longer exempts a file from ARCH-09

## [0.1.19] - 2026-11-04

### Added

- **`allow_commands` in `.harness.json` exempts a command from CONV-06** (#50): `{"command": "new", "reason": "…"}` for one name, `{"prefix": "nectar:", "reason": "…"}` for a family. The reason is required (an entry without one, or with both `command` and `prefix`, makes the file invalid). The *Public surface* gate and `pollora-qa surface` list the exempted commands as `keeps its name (allow_commands: <reason>)` rather than as a problem, so the exemption stays visible; a command that is neither exempted nor under `pollora:` is still reported. `init` and `ratchet --update` keep the list. Nothing changes for a repository that does not use it. Documented in the README

### Fixed

- **`pollora-qa check --fast` no longer fails the style gate where git does not work** (#49). In a git worktree opened from DDEV, `.git` points to a directory outside the container; Pint's `--dirty` then stopped with "The [--dirty] option is only available when using Git" and the gate failed for a reason that had nothing to do with style. The harness now asks git first (`git rev-parse --is-inside-work-tree`): when it cannot answer (broken worktree, no git binary, untrusted directory), the fast plan checks the whole tree with Pint, and runs PHPStan on all the configured paths rather than skipping it for lack of a changed file, and says so ("git is not usable here… checking the whole tree instead of the changed files"). Visible change: in that situation the fast gates are slower and may report a file you did not touch
- **A gate that could not run for want of a tool is no longer read as a pass** (#48). When Semgrep (security patterns SEC-01, SEC-03, SEC-04, SEC-05, CONV-10), Composer, npm or a `vendor/bin/` tool (Pint, PHPStan, Rector, Pest, composer-dependency-analyser) is missing, the summary marks the gate `– no tool` instead of `– skipped`, lists each one with the command that installs it (`pipx install semgrep`, `composer require --dev laravel/pint`…) and ends with "Every gate that ran passed, but not every gate ran." rather than "Every gate passed."; the job summary and `.harness/report.json` carry the same (`missing_tool: true` and `install`, the status stays `skipped`). A gate skipped for another reason (no `rector.php`, no source directory) is unchanged
- **`pollora-qa check --strict`** fails when such a gate did not run, in `observe` as in `enforce` mode, except for the gates the repository lists under `observed`. Without `--strict` the exit code is unchanged
- **A missing coverage report is no longer shown as "unmeasured"** (#47). `pollora-qa ratchet` and `pollora-qa check` now look for a Clover report at `.harness/coverage.xml` (where CI writes it), `.harness/clover.xml` or `build/logs/clover.xml` when `--coverage=FILE` is not given, and say which one they read. When a coverage or mutation score is recorded in `.harness.json` and no report is found, the verdict reads `no report` (not `unmeasured`) and a note says where it looked and how to produce one; the ratchet still does not fail for it. `check --coverage` without a value now writes and reads `.harness/coverage.xml`. Visible change: a local `ratchet` or `check` run that used to ignore a report lying under `.harness/` now compares with it, so a regression it hid shows up

## [0.1.18] - 2026-11-04

### Fixed

- **`pollora-qa init` no longer guesses** (#42). It writes `downstream` only when `--downstream=` is given or already recorded (a `core` package used to get `["framework"]` even when the framework does not require it); it pins the generated workflow to the tag of the installed harness (`php-package.yml@v0.1.17`) and keeps `@main` only when the harness runs from a branch; and it adds the `qa` and `qa:fast` scripts by editing the text of `composer.json`, so `keywords` and the indentation are no longer reformatted
- **`pollora-qa check --coverage` produces the Clover report when the coverage driver comes from `php -d`** (#43). Pest runs in a child process, which now starts with the same `zend_extension=xdebug` (and `xdebug.mode`) or `extension=pcov` flags as the parent, unless the ini files already load the driver. `XDEBUG_MODE` is inherited as before
- **`pollora-qa surface --update` records Symfony Console commands** (#44): `#[AsCommand]` with a named `name:` argument, a static `$defaultName`, and `setName()` in a class extending `Command`, besides what it already read. A repository whose commands were missing sees them as additions (label `api-change`) the next time it runs `surface`; CONV-06 reports the ones that do not start with `pollora:`

## [0.1.17] - 2026-11-03

### Fixed

- **The reusable `php-package.yml` workflow now uploads its report**: `actions/upload-artifact` skips hidden files and directories by default, so the `harness-report` step reported "No files were found with the provided path: .harness/" and published nothing; it now sets `include-hidden-files: true`. A repository gets the fix by pointing `uses: Pollora/harness/.github/workflows/php-package.yml@` at `v0.1.17`; the Composer constraint only moves the `pollora-qa` binary, not the workflow

### Changed

- **The title of CONV-06 follows the colon convention** (`pollora:make:theme`, since framework 13.32): commands are named `pollora:<verb>` or `pollora:<group>:<verb>`, and the older dashed names such as `pollora:make-theme` stay as aliases. The check is unchanged: it has only ever required the `pollora:` prefix, so no repository is affected; the generated `AGENTS.md` of a repository carries the new title the next time `pollora-qa agents` runs

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
