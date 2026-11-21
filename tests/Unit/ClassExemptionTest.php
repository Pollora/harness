<?php

declare(strict_types=1);

use Pollora\Harness\Architecture\ClassExemptions;
use Pollora\Harness\Architecture\Layers;
use Pollora\Harness\Check\Plan;
use Pollora\Harness\Config\ClassPattern;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Config\Ratchet;
use Pollora\Harness\Config\RuleExemption;
use Pollora\Harness\Console\Commands\PromoteReportCommand;
use Pollora\Harness\Console\Commands\RatchetCommand;
use Pollora\Harness\Metrics\Collector;
use Pollora\Harness\Rules\PromotionReport;

require_once __DIR__.'/../Support/CatalogueStatus.php';

if (! function_exists('classExemptProject')) {
    /**
     * A repository (namespace Acme\Lib) with open classes under Models\ and one in Services\, a closed class and a facade
     * that does more than front a contract.
     *
     * @param  list<array<string, mixed>>  $allowRules
     * @param  array<string, mixed>  $ratchet
     */
    function classExemptProject(array $allowRules = [], array $ratchet = [], bool $forPest = false): string
    {
        // Pest derives a namespace from the path of a test file; macOS's /private/var/folders/7f/… has a segment that
        // starts with a digit, which is no namespace, so a real Pest run goes through /tmp there.
        $root = $forPest && PHP_OS_FAMILY === 'Darwin' ? '/tmp/pollora-harness-'.bin2hex(random_bytes(6)) : harnessTempDir();
        is_dir($root) || mkdir($root, 0o755, true);
        mkdir($root.'/src/Models', 0o755, true);
        mkdir($root.'/src/Services');
        $files = [
            'src/Models/Post.php' => "namespace Acme\\Lib\\Models;\n\nclass Post {}",
            'src/Models/Term.php' => "namespace Acme\\Lib\\Models;\n\nclass Term {}",
            'src/Services/Mailer.php' => "namespace Acme\\Lib\\Services;\n\nclass Mailer {}",
            'src/Closed.php' => "namespace Acme\\Lib;\n\nfinal class Closed {}",
            'src/Facade.php' => "namespace Acme\\Lib;\n\nuse Illuminate\\Support\\Facades\\Facade;\n\nfinal class Shop extends Facade\n{\n    public static function extra(): void {}\n}",
            'src/Registry.php' => "namespace Acme\\Lib;\n\nfinal class Registry\n{\n    public function add(array \$args): void\n    {\n        register_post_type('book', \$args);\n    }\n}",
        ];

        foreach ($files as $path => $source) {
            file_put_contents("{$root}/{$path}", "<?php\n\ndeclare(strict_types=1);\n\n{$source}\n");
        }

        file_put_contents($root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]], JSON_UNESCAPED_SLASHES));
        file_put_contents($root.'/.harness.json', json_encode(['profile' => 'core', 'allow_rules' => $allowRules, 'ratchet' => $ratchet]));

        return $root;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<RuleExemption>
     */
    function classExemptions(array $entries): array
    {
        return array_map(static fn (array $entry): RuleExemption => RuleExemption::fromArray($entry, '.harness.json'), $entries);
    }
}

it('accepts a class name and a namespace prefix, and nothing else', function (string $pattern, bool $valid): void {
    expect(ClassPattern::isValid($pattern))->toBe($valid);
})->with([
    'a class' => ['Acme\\Foo', true],
    'a prefix' => ['Acme\\Models\\*', true],
    'a top-level prefix' => ['Acme\\*', true],
    'a bare name' => ['Foo', true],
    'a bare star' => ['*', false],
    'a star inside a name' => ['Acme\\Foo*', false],
    'a star in the middle' => ['Acme\\*\\Foo', false],
    'a regular expression' => ['/Acme\\\\Foo/', false],
    'a dot-star' => ['Acme\\.*', false],
    'empty' => ['', false],
    'a path' => ['src/Foo.php', false],
    'a double backslash' => ['Acme\\\\Foo', false],
]);

it('matches a class exactly, and a prefix at any depth below the namespace only', function (): void {
    expect(ClassPattern::matches('Acme\\Foo', 'Acme\\Foo'))->toBeTrue()
        ->and(ClassPattern::matches('Acme\\Foo', 'Acme\\FooBar'))->toBeFalse()
        ->and(ClassPattern::matches('Acme\\Foo', 'Acme\\Foo\\Bar'))->toBeFalse()
        ->and(ClassPattern::matches('Acme\\Models\\*', 'Acme\\Models\\Post'))->toBeTrue()
        ->and(ClassPattern::matches('Acme\\Models\\*', 'Acme\\Models\\Deep\\Post'))->toBeTrue()
        ->and(ClassPattern::matches('Acme\\Models\\*', 'Acme\\ModelsOther\\Post'))->toBeFalse()
        ->and(ClassPattern::matches('Acme\\Models\\*', 'Acme\\Models'))->toBeFalse()
        ->and(ClassPattern::matches('Acme\\Foo', '\\Acme\\Foo'))->toBeTrue();
});

it('reads the classes of an exemption, and writes an entry without classes exactly as before', function (): void {
    $partial = RuleExemption::fromArray(['rule' => 'CONV-08', 'reason' => ' extension points ', 'classes' => ['\\Acme\\Models\\*', 'Acme\\Foo', 'Acme\\Foo']], 'f');
    $whole = RuleExemption::fromArray(['rule' => 'CONV-08', 'reason' => 'open for now'], 'f');

    expect($partial->classes)->toBe(['Acme\\Models\\*', 'Acme\\Foo'])
        ->and($partial->isPartial())->toBeTrue()
        ->and($partial->toArray())->toBe(['rule' => 'CONV-08', 'reason' => 'extension points', 'classes' => ['Acme\\Models\\*', 'Acme\\Foo']])
        ->and($whole->isPartial())->toBeFalse()
        ->and($whole->toArray())->toBe(['rule' => 'CONV-08', 'reason' => 'open for now']);
});

it('exempts CONV-02 like the other rules that read classes', function (): void {
    expect(RuleExemption::EXEMPTIBLE)->toContain('CONV-02')
        ->and(RuleExemption::fromArray(['rule' => 'CONV-02', 'reason' => 'public API'], 'f')->rule)->toBe('CONV-02')
        ->and(Plan::ARCHITECTURE_RULES)->toContain('CONV-02')
        ->and(RuleExemption::CLASS_EXEMPTIBLE)->each->toBeIn(RuleExemption::EXEMPTIBLE);
});

it('refuses a classes list that cannot be applied, naming the entry', function (array $entry, string $message): void {
    expect(fn () => RuleExemption::fromArray($entry + ['rule' => 'CONV-08', 'reason' => 'why'], '.harness.json'))->toThrow(InvalidConfig::class, $message);
})->with([
    'a rule that is not read class by class' => [['rule' => 'ARCH-09', 'classes' => ['Acme\\Foo']], 'only CONV-02, CONV-08, CONV-09'],
    'an empty list' => [['classes' => []], 'non-empty list'],
    'not a list' => [['classes' => 'Acme\\Foo'], 'non-empty list'],
    'an object' => [['classes' => ['a' => 'Acme\\Foo']], 'non-empty list'],
    'a regular expression' => [['classes' => ['/Acme/']], 'No other pattern is supported'],
    'a bare star' => [['classes' => ['*']], 'No other pattern is supported'],
    'a star inside a name' => [['classes' => ['Acme\\Foo*']], '"Acme\\Foo*"'],
    'not a string' => [['classes' => [12]], '"int"'],
]);

it('keeps several per-class entries for a rule, and refuses to mix them with a whole-rule entry', function (): void {
    $two = HarnessConfig::fromArray(['profile' => 'core', 'allow_rules' => [
        ['rule' => 'CONV-08', 'reason' => 'models', 'classes' => ['Acme\\Models\\*']],
        ['rule' => 'CONV-08', 'reason' => 'services', 'classes' => ['Acme\\Services\\*']],
    ]]);

    expect($two->allowRules)->toHaveCount(2);

    foreach ([
        [['rule' => 'CONV-08', 'reason' => 'a'], ['rule' => 'CONV-08', 'reason' => 'b', 'classes' => ['Acme\\Foo']]],
        [['rule' => 'CONV-08', 'reason' => 'b', 'classes' => ['Acme\\Foo']], ['rule' => 'CONV-08', 'reason' => 'a']],
    ] as $entries) {
        expect(fn () => HarnessConfig::fromArray(['profile' => 'core', 'allow_rules' => $entries]))->toThrow(InvalidConfig::class, 'as a whole and for some classes');
    }

    expect(fn () => HarnessConfig::fromArray(['profile' => 'core', 'allow_rules' => [['rule' => 'CONV-08', 'reason' => 'a'], ['rule' => 'CONV-08', 'reason' => 'b']]]))->toThrow(InvalidConfig::class, 'twice');
});

it('round-trips the classes of an entry through the config', function (): void {
    $entries = [['rule' => 'CONV-08', 'reason' => 'models', 'classes' => ['Acme\\Models\\*']], ['rule' => 'CONV-09', 'reason' => 'all']];

    expect(HarnessConfig::fromArray(['profile' => 'core', 'allow_rules' => $entries])->toArray()['allow_rules'])->toBe($entries);
});

it('removes the exempted classes from the problems and names them', function (): void {
    $root = classExemptProject();
    $layers = Layers::discover($root);
    $problems = ClassExemptions::problemsOf('CONV-08', $layers, $root);
    $result = ClassExemptions::apply(classExemptions([['rule' => 'CONV-08', 'reason' => 'models', 'classes' => ['Acme\\Lib\\Models\\*', 'Acme\\Lib\\Services\\Mailer']]]), $problems, $layers, $root);

    expect($problems)->toHaveCount(3)
        ->and($result->kept)->toBe([])
        ->and($result->classes())->toBe(['Acme\\Lib\\Models\\Post', 'Acme\\Lib\\Models\\Term', 'Acme\\Lib\\Services\\Mailer'])
        ->and($result->effects[0]->message())->toBe('exempted: CONV-08 — models — 3 classes: Acme\\Lib\\Models\\Post, Acme\\Lib\\Models\\Term, Acme\\Lib\\Services\\Mailer')
        ->and($result->effects[0]->message(false))->toStartWith('declared, not applied while the rule is observed: CONV-08')
        ->and($result->warnings())->toBe([]);
});

it('keeps judging the classes an exact pattern does not name', function (): void {
    $root = classExemptProject();
    $layers = Layers::discover($root);
    $result = ClassExemptions::apply(classExemptions([['rule' => 'CONV-08', 'reason' => 'one', 'classes' => ['Acme\\Lib\\Models\\Post']]]), ClassExemptions::problemsOf('CONV-08', $layers, $root), $layers, $root);

    expect($result->kept)->toHaveCount(2)
        ->and(implode("\n", $result->kept))->toContain('Acme\\Lib\\Models\\Term')->toContain('Acme\\Lib\\Services\\Mailer')->not->toContain('Acme\\Lib\\Models\\Post is')
        ->and($result->effects[0]->message())->toBe('exempted: CONV-08 — one — 1 class: Acme\\Lib\\Models\\Post');
});

it('reports a pattern that matches no class, and one that matches only classes the rule does not report', function (): void {
    $root = classExemptProject();
    $layers = Layers::discover($root);
    $result = ClassExemptions::apply(classExemptions([['rule' => 'CONV-08', 'reason' => 'old', 'classes' => ['Acme\\Gone', 'Acme\\Removed\\*', 'Acme\\Closed', 'Acme\\Lib\\Closed', 'Acme\\Lib\\Models\\Post']]]), ClassExemptions::problemsOf('CONV-08', $layers, $root), $layers, $root);
    $warnings = $result->warnings();

    expect($result->effects[0]->stale)->toBe(['Acme\\Gone', 'Acme\\Removed\\*', 'Acme\\Closed'])
        ->and($result->effects[0]->unused)->toBe(['Acme\\Lib\\Closed'])
        ->and($warnings)->toHaveCount(4)
        ->and($warnings[0])->toBe('stale exemption: CONV-08 — "Acme\\Gone" matches no class of the repository (remove it).')
        ->and($warnings[3])->toContain('"Acme\\Lib\\Closed" matches no class that the rule reports')
        ->and($result->classes())->toBe(['Acme\\Lib\\Models\\Post']);
});

it('attributes a problem to the first exemption that covers it', function (): void {
    $root = classExemptProject();
    $layers = Layers::discover($root);
    $result = ClassExemptions::apply(classExemptions([
        ['rule' => 'CONV-08', 'reason' => 'first', 'classes' => ['Acme\\Lib\\Models\\*']],
        ['rule' => 'CONV-08', 'reason' => 'second', 'classes' => ['Acme\\Lib\\Models\\Post', 'Acme\\Lib\\Services\\Mailer']],
    ]), ClassExemptions::problemsOf('CONV-08', $layers, $root), $layers, $root);

    expect($result->effects[0]->classes)->toHaveCount(2)
        ->and($result->effects[1]->classes)->toBe(['Acme\\Lib\\Services\\Mailer'])
        ->and($result->effects[1]->unused)->toBe([])
        ->and($result->kept)->toBe([]);
});

it('exempts the classes CONV-09 and CONV-02 report, which name the class in another way', function (): void {
    $root = classExemptProject();
    $layers = Layers::discover($root);
    $facade = ClassExemptions::problemsOf('CONV-09', $layers, $root);
    $raw = ClassExemptions::problemsOf('CONV-02', $layers, $root);

    expect($facade)->not->toBe([])
        ->and($raw)->not->toBe([])
        ->and(ClassExemptions::apply(classExemptions([['rule' => 'CONV-09', 'reason' => 'r', 'classes' => ['Acme\\Lib\\Shop']]]), $facade, $layers, $root)->kept)->toBe([])
        ->and(ClassExemptions::apply(classExemptions([['rule' => 'CONV-02', 'reason' => 'r', 'classes' => ['Acme\\Lib\\Registry']]]), $raw, $layers, $root)->kept)->toBe([])
        ->and(ClassExemptions::apply(classExemptions([['rule' => 'CONV-02', 'reason' => 'r', 'classes' => ['Acme\\Lib\\Other']]]), $raw, $layers, $root)->kept)->toBe($raw);
});

it('counts the exempted classes for the ratchet, and the whole-rule exemptions apart', function (): void {
    $partial = [['rule' => 'CONV-08', 'reason' => 'models', 'classes' => ['Acme\\Lib\\Models\\*']]];
    $root = classExemptProject($partial);

    expect(Collector::exemptedClasses(HarnessConfig::load($root), $root))->toBe(2)
        ->and(Collector::exemptedRules(HarnessConfig::load($root)))->toBeNull()
        ->and(Collector::exemptedClasses(HarnessConfig::fromArray(['profile' => 'core']), $root))->toBeNull()
        ->and(Collector::exemptedClasses(HarnessConfig::fromArray(['profile' => 'core', 'ratchet' => ['exempted_classes' => 4]]), $root))->toBe(0);

    $whole = HarnessConfig::fromArray(['profile' => 'core', 'allow_rules' => [['rule' => 'CONV-09', 'reason' => 'x'], ...$partial]]);

    expect(Collector::exemptedRules($whole))->toBe(1)->and(Collector::exemptedClasses($whole, $root))->toBe(2);
});

it('counts a class only when the rule reports it, so a namespace cannot widen unnoticed', function (): void {
    $root = classExemptProject([['rule' => 'CONV-08', 'reason' => 'all', 'classes' => ['Acme\\Lib\\*']]]);
    $before = Collector::exemptedClasses(HarnessConfig::load($root), $root);
    file_put_contents($root.'/src/Models/Comment.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Lib\\Models;\n\nclass Comment {}\n");
    file_put_contents($root.'/src/Models/Fine.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Lib\\Models;\n\nfinal class Fine {}\n");

    expect($before)->toBe(3)->and(Collector::exemptedClasses(HarnessConfig::load($root), $root))->toBe(4);
});

it('fails the ratchet when the exempted classes grow, improves when they shrink, and keeps an old file unmeasured', function (): void {
    $ratchet = new Ratchet(['exempted_classes' => 3]);
    $verdict = static fn (Ratchet $ratchet, ?int $measured): string => array_values(array_filter($ratchet->compare(['exempted_classes' => $measured]), static fn (array $row): bool => $row['metric'] === 'exempted_classes'))[0]['verdict'];

    expect($verdict($ratchet, 4))->toBe('regressed')
        ->and($verdict($ratchet, 3))->toBe('unchanged')
        ->and($verdict($ratchet, 1))->toBe('improved')
        ->and($verdict(new Ratchet, null))->toBe('unmeasured')
        ->and($ratchet->tighten(['exempted_classes' => 5])->get('exempted_classes'))->toBe(3)
        ->and($ratchet->tighten(['exempted_classes' => 0])->get('exempted_classes'))->toBe(0)
        ->and(fn () => Ratchet::validate(['exempted_classes' => -1], 'f'))->toThrow(InvalidConfig::class)
        ->and(fn () => Ratchet::validate(['exempted_classes' => 2], 'f'))->not->toThrow(InvalidConfig::class);
});

it('says in promote-report what is exempted and what remains', function (): void {
    $root = classExemptProject([['rule' => 'CONV-08', 'reason' => 'models', 'classes' => ['Acme\\Lib\\Models\\*']]]);
    $rows = [];

    foreach (PromotionReport::build($root) as $row) {
        $rows[$row['rule']] = $row;
    }

    [$code, $out] = harnessRun(new PromoteReportCommand, $root, '--no-ansi');

    expect($rows['CONV-08']['found'])->toBe(3)
        ->and($rows['CONV-08']['count'])->toBe(1)
        ->and($rows['CONV-08']['exempted_problems'])->toBe(2)
        ->and($rows['CONV-08']['exempted_classes'])->toBe(['Acme\\Lib\\Models\\Post', 'Acme\\Lib\\Models\\Term'])
        ->and($rows['CONV-08']['verdict'])->toBe(PromotionReport::WOULD_BLOCK)
        ->and($rows['CONV-08']['exempted'])->toBeNull()
        ->and($rows['CONV-09']['found'])->toBe($rows['CONV-09']['count'])
        ->and($code)->toBe(0)
        ->and($out)->toContain('CONV-08  would block (3 found, 2 exempted by allow_rules in 2 classes: 1 remaining)')
        ->and($out)->toContain('Acme\\Lib\\Services\\Mailer is not final');
});

it('reads a rule as clean once every problem is exempted, and shows the stale patterns', function (): void {
    $root = classExemptProject([['rule' => 'CONV-08', 'reason' => 'all', 'classes' => ['Acme\\Lib\\Models\\*', 'Acme\\Lib\\Services\\*', 'Acme\\Lib\\Gone']]]);
    $row = array_values(array_filter(PromotionReport::build($root), static fn (array $row): bool => $row['rule'] === 'CONV-08'))[0];
    [, $out] = harnessRun(new PromoteReportCommand, $root, '--no-ansi');

    expect($row['verdict'])->toBe(PromotionReport::CLEAN)
        ->and($row['count'])->toBe(0)
        ->and($row['stale'])->toHaveCount(1)
        ->and($out)->toContain('CONV-08  clean (3 found, 3 exempted by allow_rules in 3 classes: 0 remaining)')
        ->and($out)->toContain('stale exemption: CONV-08 — "Acme\\Lib\\Gone"');
});

it('leaves the report of a repository without per-class exemption as it was', function (): void {
    $root = classExemptProject();
    $row = array_values(array_filter(PromotionReport::build($root), static fn (array $row): bool => $row['rule'] === 'CONV-08'))[0];
    [, $out] = harnessRun(new PromoteReportCommand, $root, '--no-ansi');

    expect($row['count'])->toBe(3)->and($row['found'])->toBe(3)->and($row['exempted_problems'])->toBe(0)->and($row['stale'])->toBe([])
        ->and($out)->toContain('CONV-08  would block (3 problems)');
});

it('names the per-class exemptions in the notes of the architecture steps, applied or not', function (): void {
    $root = classExemptProject();
    $config = HarnessConfig::fromArray(['profile' => 'core', 'allow_rules' => [['rule' => 'CONV-08', 'reason' => 'models', 'classes' => ['Acme\\Lib\\Models\\*', 'Acme\\Lib\\Gone']]]]);
    $notes = static fn (): array => array_map(static fn ($step): ?string => $step->note, array_values(array_filter((new Plan($config, $root))->full(), static fn ($step): bool => $step->name === 'Tests and architecture')));

    $observed = $notes()[0];
    $enforced = withCatalogueStatus('CONV-08', 'enforced', $notes)[0];

    expect($observed)->toContain('declared, not applied while the rule is observed: CONV-08 — models — 2 classes: Acme\\Lib\\Models\\Post, Acme\\Lib\\Models\\Term (allow_rules)')
        ->and($enforced)->toContain('exempted: CONV-08 — models — 2 classes')
        ->and($enforced)->toContain('stale exemption: CONV-08 — "Acme\\Lib\\Gone"')
        ->and(array_filter((new Plan($config, $root))->full(), static fn ($step): bool => $step->exempted !== [] || $step->exemptionSummary() !== null))->toBe([])
        ->and(array_filter(withCatalogueStatus('CONV-08', 'enforced', static fn (): array => (new Plan($config, $root))->full()), static fn ($step): bool => $step->exempted !== []))->toBe([]);
});

if (! function_exists('classArchRun')) {
    /**
     * Runs the CONV-08 tests of a repository by a real Pest run, with the rule `enforced` or left `observed`.
     *
     * @param  list<array<string, mixed>>  $allowRules
     * @return array{int, string}
     */
    function classArchRun(array $allowRules, bool $enforced): array
    {
        $root = classExemptProject($allowRules, forPest: true);
        mkdir($root.'/tests');
        mkdir($root.'/vendor');
        file_put_contents($root.'/vendor/autoload.php', "<?php\n\nreturn require '".dirname(__DIR__, 2)."/vendor/autoload.php';\n");
        file_put_contents($root.'/phpunit.xml', '<phpunit cacheDirectory=".phpunit.cache"><testsuites><testsuite name="Arch"><file>tests/Arch.php</file></testsuite></testsuites></phpunit>');
        file_put_contents($root.'/tests/Arch.php', "<?php\n\ndeclare(strict_types=1);\n\n".($enforced ? "use Pollora\\Harness\\Rules\\Catalogue;\n\n\$property = new ReflectionProperty(Catalogue::class, 'rules');\n\$all = Catalogue::all();\n\$all['CONV-08']['status'] = 'enforced';\n\$property->setValue(null, \$all);\n\n" : '')."Pollora\\Harness\\Pest\\Architecture::for(dirname(__DIR__))->register();\n");

        $command = [PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/pest', 'tests/Arch.php', '--colors=never', '--filter=CONV-08'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, [...getenv(), 'COLUMNS' => '200']);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $output];
    }
}

it('runs the rule on the other classes and names the exempted ones in a real run', function (): void {
    [$code, $output] = classArchRun([['rule' => 'CONV-08', 'reason' => 'models are extension points', 'classes' => ['Acme\\Lib\\Models\\*', 'Acme\\Lib\\Gone']]], true);

    expect($code)->not->toBe(0)
        ->and($output)->toContain('Acme\\Lib\\Services\\Mailer is not final')
        ->and($output)->not->toContain('Acme\\Lib\\Models\\Post is not final')
        ->and($output)->toContain('stale exemption: CONV-08 — "Acme\\Lib\\Gone"')
        ->and($output)->toContain('exempted: CONV-08 — models are extension points — 2 classes: Acme\\Lib\\Models\\Post, Acme\\Lib\\Models\\Term');
});

it('passes once the exempted classes are all that the rule reports', function (): void {
    [$code, $output] = classArchRun([['rule' => 'CONV-08', 'reason' => 'extension points', 'classes' => ['Acme\\Lib\\Models\\*', 'Acme\\Lib\\Services\\*']]], true);

    expect($code)->toBe(0)->and($output)->toContain('exempted: CONV-08 — extension points — 3 classes');
});

it('declares the exemption without applying it while the rule is observed', function (): void {
    [$code, $output] = classArchRun([['rule' => 'CONV-08', 'reason' => 'extension points', 'classes' => ['Acme\\Lib\\Models\\*']]], false);

    expect($code)->toBe(0)
        ->and($output)->toContain('observed, not blocking: CONV-08 — 3 problem(s)')
        ->and($output)->toContain('declared, not applied while the rule is observed: CONV-08 — extension points — 2 classes');
});

it('records the exempted classes with ratchet --update, keeps the classes of the entry, and then fails when they grow', function (): void {
    $root = classExemptProject([['rule' => 'CONV-08', 'reason' => 'models', 'classes' => ['Acme\\Lib\\Models\\*']]]);
    [$code, $out] = harnessRun(new RatchetCommand, $root, '--update', '--no-ansi', '--metric=type_coverage:100');
    $written = json_decode((string) file_get_contents($root.'/.harness.json'), true);

    expect($code)->toBe(0)
        ->and($written['ratchet']['exempted_classes'])->toBe(2)
        ->and($written['allow_rules'][0]['classes'])->toBe(['Acme\\Lib\\Models\\*']);

    file_put_contents($root.'/src/Models/Comment.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Lib\\Models;\n\nclass Comment {}\n");
    [$after, $table] = harnessRun(new RatchetCommand, $root, '--no-ansi', '--metric=type_coverage:100');

    expect($after)->not->toBe(0)->and($table)->toContain('Classes exempted from an architecture rule')->toContain('regressed');
});
