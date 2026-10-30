<?php

declare(strict_types=1);

/*
 * The rule catalogue: the single source for every rule identifier the harness
 * enforces. Error messages, the generated AGENTS.md and the README all read
 * from here, so a rule is renamed or reworded in one place.
 *
 * gate: G0 local and agent hooks, G1 fast pull request checks, G2 full pull
 * request checks (blocks the merge), G3 nightly, G4 release.
 * status: "enforced" fails the gate, "observed" only reports, "planned" is not
 * checked yet by this version of the harness.
 */

return [
    // Architecture
    'ARCH-01' => ['title' => 'Domain code imports neither Illuminate nor the Infrastructure or UI layers', 'tool' => 'Pest arch', 'gate' => 'G1', 'status' => 'enforced'],
    'ARCH-02' => ['title' => 'Domain code calls no global WordPress function', 'tool' => 'PHPStan rule pollora.noWordPressCallInDomain', 'gate' => 'G1', 'status' => 'enforced'],
    'ARCH-03' => ['title' => 'Application code depends on the Domain layer only', 'tool' => 'Pest arch', 'gate' => 'G1', 'status' => 'enforced'],
    'ARCH-04' => ['title' => 'A core package requires neither illuminate/* nor laravel/*, unless listed in .harness.json', 'tool' => 'pollora-qa deps', 'gate' => 'G1', 'status' => 'enforced'],
    'ARCH-05' => ['title' => 'The framework core references no third-party plugin (WooCommerce, ACF, Meta Box, WP Rocket)', 'tool' => 'Semgrep', 'gate' => 'G1', 'status' => 'observed'],
    'ARCH-06' => ['title' => 'Only integration packages, templates and projects depend on pollora/framework', 'tool' => 'pollora-qa deps', 'gate' => 'G1', 'status' => 'enforced'],
    'ARCH-07' => ['title' => 'Every dependency used is declared, none declared is unused', 'tool' => 'composer-dependency-analyser', 'gate' => 'G1', 'status' => 'enforced'],
    'ARCH-08' => ['title' => 'Attribute classes are final, readonly and declare their targets', 'tool' => 'Pest arch', 'gate' => 'G1', 'status' => 'enforced'],
    'ARCH-09' => ['title' => 'declare(strict_types=1) in every file', 'tool' => 'Pest arch + Pint', 'gate' => 'G1', 'status' => 'enforced'],

    // Coding conventions
    'CONV-01' => ['title' => 'Expressive API: the common case needs no configuration; options are chained methods or named arguments', 'tool' => 'Review + documentation examples run as tests', 'gate' => 'G2', 'status' => 'planned'],
    'CONV-02' => ['title' => 'No raw WordPress argument array in the public API', 'tool' => 'PHPStan', 'gate' => 'G1', 'status' => 'planned'],
    'CONV-03' => ['title' => 'Laravel naming: ServiceProvider, Command, Exception, Repository, Factory suffixes', 'tool' => 'Pest arch', 'gate' => 'G1', 'status' => 'enforced'],
    'CONV-04' => ['title' => 'Hooks fired by Pollora are named pollora/<module>/<event_snake_case>', 'tool' => 'pollora-qa surface', 'gate' => 'G2', 'status' => 'observed'],
    'CONV-05' => ['title' => 'Configuration lives under pollora.<package>; env() is only called in config/', 'tool' => 'Pest arch', 'gate' => 'G1', 'status' => 'enforced'],
    'CONV-06' => ['title' => 'Commands are named pollora:<verb> or pollora:make-<thing>', 'tool' => 'pollora-qa surface', 'gate' => 'G2', 'status' => 'observed'],
    'CONV-07' => ['title' => 'Domain and Application code never calls app() or resolve(); it receives contracts', 'tool' => 'Pest arch', 'gate' => 'G1', 'status' => 'enforced'],
    'CONV-08' => ['title' => 'Public classes stay open (final only on attributes and internal classes); builders and facade-backed services use Macroable', 'tool' => 'Pest arch', 'gate' => 'G1', 'status' => 'planned'],
    'CONV-09' => ['title' => 'A facade fronts an injectable service, with a contract and a fake() for tests', 'tool' => 'Pest arch', 'gate' => 'G1', 'status' => 'planned'],
    'CONV-10' => ['title' => 'One named exception per business case; never throw a bare \\Exception', 'tool' => 'Semgrep', 'gate' => 'G1', 'status' => 'enforced'],
    'CONV-11' => ['title' => 'Native types everywhere; PHPDoc only for generics, array shapes, @internal, @deprecated', 'tool' => 'PHPStan + Pest type coverage', 'gate' => 'G1', 'status' => 'observed'],
    'CONV-12' => ['title' => 'Readable Pest tests: one intention per test, datasets over copy-paste', 'tool' => 'Review', 'gate' => 'G2', 'status' => 'planned'],

    // Security
    'SEC-01' => ['title' => 'A REST route declared by attribute states its permission; a public route says so explicitly', 'tool' => 'Semgrep', 'gate' => 'G1', 'status' => 'observed'],
    'SEC-02' => ['title' => 'An AJAX action checks a nonce by default; guest access is explicit', 'tool' => 'Semgrep + integration test', 'gate' => 'G1', 'status' => 'planned'],
    'SEC-03' => ['title' => 'No $wpdb query built by interpolation without prepare()', 'tool' => 'Semgrep', 'gate' => 'G1', 'status' => 'enforced'],
    'SEC-04' => ['title' => 'No unescaped Blade output {!! !!} outside a commented allowlist', 'tool' => 'Semgrep', 'gate' => 'G1', 'status' => 'enforced'],
    'SEC-05' => ['title' => 'No unserialize, eval or extract', 'tool' => 'Semgrep', 'gate' => 'G1', 'status' => 'enforced'],
    'SEC-06' => ['title' => 'On-the-fly thumbnails only accept declared sizes', 'tool' => 'Contract test (media)', 'gate' => 'G2', 'status' => 'planned'],
    'SEC-07' => ['title' => 'Every administration action checks a capability', 'tool' => 'Integration test per role', 'gate' => 'G2', 'status' => 'planned'],
    'SEC-08' => ['title' => 'No secret in the repository', 'tool' => 'gitleaks', 'gate' => 'G1', 'status' => 'enforced'],
    'SEC-09' => ['title' => 'No dependency with a known vulnerability', 'tool' => 'composer audit', 'gate' => 'G1', 'status' => 'enforced'],

    // Performance
    'PERF-01' => ['title' => 'SQL queries per page type never exceed the reference', 'tool' => 'Golden project', 'gate' => 'G2', 'status' => 'planned'],
    'PERF-02' => ['title' => 'Discovery reads no file when its cache is warm', 'tool' => 'Integration test (discovery)', 'gate' => 'G2', 'status' => 'planned'],
    'PERF-03' => ['title' => 'No N+1 query in Colt', 'tool' => 'Integration test (colt)', 'gate' => 'G2', 'status' => 'planned'],
    'PERF-07' => ['title' => 'Boot, cold discovery and thumbnail timings tracked nightly, alert above 10 %', 'tool' => 'PHPBench', 'gate' => 'G3', 'status' => 'planned'],

    // Process
    'QA-01' => ['title' => 'Ratchet: no metric gets worse (PHPStan level and baseline, coverage, mutation score)', 'tool' => 'pollora-qa ratchet', 'gate' => 'G2', 'status' => 'enforced'],
    'QA-02' => ['title' => 'The harness files are protected: baseline, .harness.json and api-surface.json change only through a human', 'tool' => 'Agent hooks + CODEOWNERS', 'gate' => 'G0', 'status' => 'enforced'],
    'QA-04' => ['title' => 'The public surface (attributes, hooks fired, configuration keys, commands) changes only with a label: api-change to add, breaking to remove or change', 'tool' => 'pollora-qa surface', 'gate' => 'G2', 'status' => 'enforced'],
    'QA-05' => ['title' => 'No PHP signature breaks backward compatibility outside a major version', 'tool' => 'roave/backward-compatibility-check', 'gate' => 'G2', 'status' => 'enforced'],
    'QA-06' => ['title' => 'The archive of a tagged release carries only what runs: tests, CI workflows, tool configuration and agent files are export-ignored', 'tool' => 'pollora-qa archive', 'gate' => 'G2', 'status' => 'enforced'],
    'QA-03' => ['title' => 'A change to a core package keeps the framework green', 'tool' => 'Downstream job', 'gate' => 'G2', 'status' => 'observed'],
];
