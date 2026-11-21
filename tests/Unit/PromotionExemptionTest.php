<?php

declare(strict_types=1);

use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\Step;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Config\RuleExemption;
use Pollora\Harness\Metrics\Collector;

require_once __DIR__.'/../Support/CatalogueStatus.php';

/**
 * A project with one CONV-08 problem (a class that is not final) and one CONV-12 problem (a PHPUnit test class),
 * checked by a real Pest run of tests/Arch.php.
 *
 * @param  list<array{rule: string, reason: string}>  $allowRules
 * @return array{int, string}
 */
function promotionRun(array $allowRules): array
{
    // under /tmp with a name that starts with a letter: Pest derives a namespace from the path, which a
    // macOS temporary directory (/private/var/folders/7f/…, a segment starting with a digit) breaks
    $root = '/tmp/pollora-harness-promo-'.bin2hex(random_bytes(5));
    mkdir($root.'/src', 0o755, true);
    mkdir($root.'/tests');
    mkdir($root.'/vendor');
    file_put_contents($root.'/vendor/autoload.php', "<?php\n\n\$loader = require '".dirname(__DIR__, 2)."/vendor/autoload.php';\n\$loader->addPsr4('Acme\\\\Shared\\\\', __DIR__.'/../src');\n\nreturn \$loader;\n");
    file_put_contents($root.'/phpunit.xml', '<phpunit cacheDirectory=".phpunit.cache"><testsuites><testsuite name="Arch"><file>tests/Arch.php</file></testsuite></testsuites></phpunit>');
    file_put_contents($root.'/composer.json', '{"autoload": {"psr-4": {"Acme\\\\Shared\\\\": "src/"}}}');
    file_put_contents($root.'/.harness.json', json_encode(['profile' => 'core', 'allow_rules' => $allowRules]));
    file_put_contents($root.'/src/Open.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Shared;\n\nclass Open {}\n");
    file_put_contents($root.'/tests/Arch.php', "<?php\n\ndeclare(strict_types=1);\n\nPollora\\Harness\\Pest\\Architecture::for(dirname(__DIR__))->register();\n");

    $command = [PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/pest', 'tests/Arch.php', '--colors=never', '--filter=CONV-08'];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, [...getenv(), 'COLUMNS' => '200']);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $output];
}

it('accepts an exemption of CONV-08, CONV-09 and CONV-12 that carries a reason', function (string $rule): void {
    $exemption = RuleExemption::fromArray(['rule' => $rule, 'reason' => 'published classes stay open for now'], '.harness.json');

    expect($exemption->rule)->toBe($rule)
        ->and(HarnessConfig::fromArray(['profile' => 'core', 'allow_rules' => [['rule' => $rule, 'reason' => 'x']]])->allowRules)->toHaveCount(1);
})->with(['CONV-08', 'CONV-09', 'CONV-12']);

it('refuses an exemption of CONV-08, CONV-09 and CONV-12 without a reason', function (string $rule, mixed $reason): void {
    expect(fn () => RuleExemption::fromArray(['rule' => $rule, 'reason' => $reason], '.harness.json'))
        ->toThrow(InvalidConfig::class, 'without a reason');
})->with(['CONV-08', 'CONV-09', 'CONV-12'])->with([null, '', '   ']);

it('names the three rules among those a repository may exempt, and keeps a rule of another gate out', function (): void {
    expect(RuleExemption::EXEMPTIBLE)->toContain('CONV-08', 'CONV-09', 'CONV-12')
        ->and(fn () => RuleExemption::fromArray(['rule' => 'CONV-11', 'reason' => 'x'], '.harness.json'))->toThrow(InvalidConfig::class, 'Only ARCH-01');
});

it('lists in the summary of the architecture steps exactly the rules Architecture::register evaluates', function (): void {
    $sorted = static function (array $rules): array {
        sort($rules);

        return $rules;
    };
    $plan = new Plan(HarnessConfig::fromArray(['profile' => 'core']), harnessTempDir());
    $named = array_values(array_filter([...$plan->fast([]), ...$plan->full()], static fn (Step $step): bool => in_array($step->name, ['Architecture', 'Tests and architecture'], true)));

    expect($named)->toHaveCount(2)
        ->and($sorted(Plan::ARCHITECTURE_RULES))->toBe($sorted(RuleExemption::EXEMPTIBLE));

    foreach ($named as $step) {
        expect($step->rules)->toBe(Plan::ARCHITECTURE_RULES)->toContain('CONV-08', 'CONV-09', 'CONV-12');
    }
});

it('applies an exemption once the rule is enforced and holds it back while the rule is observed', function (): void {
    $exemption = new RuleExemption('CONV-08', 'published classes stay open for now');

    expect($exemption->applies())->toBeFalse()
        ->and($exemption->message())->toBe('declared, not applied while the rule is observed: CONV-08 — published classes stay open for now');

    withCatalogueStatus('CONV-08', 'enforced', function () use ($exemption): void {
        expect($exemption->applies())->toBeTrue()
            ->and($exemption->message())->toBe('exempted: CONV-08 — published classes stay open for now');
    });

    expect($exemption->applies())->toBeFalse();
});

it('tells the gate which exemptions are applied, and says the others are only declared', function (): void {
    $config = HarnessConfig::fromArray(['profile' => 'core', 'allow_rules' => [
        ['rule' => 'ARCH-08', 'reason' => 'published classes'],
        ['rule' => 'CONV-08', 'reason' => 'open for now'],
    ]]);
    $step = array_values(array_filter((new Plan($config, harnessTempDir()))->full(), static fn (Step $step): bool => $step->name === 'Tests and architecture'))[0];

    expect($step->exempted)->toBe(['ARCH-08'])
        ->and($step->note)->toContain('exempted: ARCH-08 — published classes (allow_rules)')
        ->toContain('declared, not applied while the rule is observed: CONV-08 — open for now (allow_rules)');

    $promoted = withCatalogueStatus('CONV-08', 'enforced', fn (): array => array_values(array_filter((new Plan($config, harnessTempDir()))->full(), static fn (Step $step): bool => $step->name === 'Tests and architecture'))[0]->exempted);

    expect($promoted)->toBe(['ARCH-08', 'CONV-08']);
});

it('keeps reporting an observed rule that is exempted, without any error', function (): void {
    [$code, $output] = promotionRun([['rule' => 'CONV-08', 'reason' => 'open for now']]);

    expect($code)->toBe(0)
        ->and($output)->toContain('observed, not blocking: CONV-08')
        ->and($output)->toContain('Acme\\Shared\\Open')
        ->and($output)->not->toContain('exempted: CONV-08');
});

it('counts a declared exemption in the ceiling, which can only go down', function (): void {
    $config = HarnessConfig::fromArray(['profile' => 'core', 'allow_rules' => [['rule' => 'CONV-08', 'reason' => 'open for now']], 'ratchet' => ['exempted_rules' => 1]]);
    $measured = ['exempted_rules' => Collector::exemptedRules($config)];

    expect($measured['exempted_rules'])->toBe(1)
        ->and($config->ratchet->compare($measured)[5]['verdict'])->toBe('unchanged');

    $two = ['exempted_rules' => 2];
    $zero = ['exempted_rules' => 0];

    expect($config->ratchet->compare($two)[5]['verdict'])->toBe('regressed')
        ->and($config->ratchet->tighten($two)->get('exempted_rules'))->toBe(1)
        ->and($config->ratchet->tighten($zero)->get('exempted_rules'))->toBe(0);
});
