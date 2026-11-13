<?php

declare(strict_types=1);

use Pollora\Harness\Console\Application;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Commands\AgentsCommand;
use Pollora\Harness\Console\Commands\ArchiveCommand;
use Pollora\Harness\Console\Commands\CheckCommand;
use Pollora\Harness\Console\Commands\DepsCommand;
use Pollora\Harness\Console\Commands\RatchetCommand;
use Pollora\Harness\Console\Commands\RulesCommand;
use Pollora\Harness\Console\Commands\SurfaceCommand;
use Pollora\Harness\Console\Io;

afterEach(function (): void {
    putenv('GITHUB_STEP_SUMMARY');
});

function harnessConfigure(string $cwd, array $config = []): void
{
    file_put_contents($cwd.'/.harness.json', json_encode(['profile' => 'core', ...$config]));
}

it('lists the rules, one rule, or the rules of one status', function (): void {
    $cwd = harnessTempDir();

    [$code, $all] = harnessRun(new RulesCommand, $cwd);
    [, $one] = harnessRun(new RulesCommand, $cwd, 'arch-02');
    [, $enforced] = harnessRun(new RulesCommand, $cwd, '--status=enforced');
    [, $none] = harnessRun(new RulesCommand, $cwd, '--status=no-such-status');

    expect($code)->toBe(0)
        ->and($all)->toContain('ARCH-01', 'ARCH-02')
        ->and($one)->toContain('ARCH-02')->not->toContain('ARCH-01')
        ->and($enforced)->not->toBe('')
        ->and(strlen($enforced))->toBeLessThanOrEqual(strlen($all))
        ->and($none)->toBe('');
});

it('reports dependencies that break the profile and passes clean ones', function (): void {
    $cwd = harnessTempDir();
    harnessConfigure($cwd);
    file_put_contents($cwd.'/composer.json', '{"require": {"php": "^8.3"}}');

    [$clean, $out] = harnessRun(new DepsCommand, $cwd);

    expect($clean)->toBe(0)->and($out)->toContain('ARCH-04');

    file_put_contents($cwd.'/composer.json', '{"require": {"php": "^8.3", "illuminate/support": "^13.0"}}');

    [$code, $out] = harnessRun(new DepsCommand, $cwd);

    expect($code)->toBe(1)->and($out)->toContain('illuminate/support');
});

it('writes AGENTS.md, then reports it up to date, and fails --check when it is stale', function (): void {
    $cwd = harnessTempDir();
    harnessConfigure($cwd);

    [$stale, , $err] = harnessRun(new AgentsCommand, $cwd, '--check');
    expect($stale)->toBe(1)->and($err)->toContain('out of date')->and(is_file($cwd.'/AGENTS.md'))->toBeFalse();

    [$written, $out] = harnessRun(new AgentsCommand, $cwd);
    expect($written)->toBe(0)->and($out)->toContain('updated')->and(is_file($cwd.'/AGENTS.md'))->toBeTrue();

    [$again, $out] = harnessRun(new AgentsCommand, $cwd, '--check');
    expect($again)->toBe(0)->and($out)->toContain('up to date');
});

it('keeps what is already in AGENTS.md when it adds its block', function (): void {
    $cwd = harnessTempDir();
    harnessConfigure($cwd);
    file_put_contents($cwd.'/AGENTS.md', "# Mine\n\nKeep this line.\n");

    harnessRun(new AgentsCommand, $cwd);

    expect((string) file_get_contents($cwd.'/AGENTS.md'))->toContain('Keep this line.')->toContain('ARCH-');
});

it('does not check the archive of a template', function (): void {
    $cwd = harnessTempDir();
    harnessConfigure($cwd, ['profile' => 'template']);

    [$code, $out] = harnessRun(new ArchiveCommand, $cwd);

    expect($code)->toBe(0)->and($out)->toContain('its own product');
});

it('cannot build the archive outside a git repository', function (): void {
    $cwd = harnessTempDir();
    harnessConfigure($cwd);

    [$code, , $err] = harnessRun(new ArchiveCommand, $cwd);

    expect($code)->toBe(1)->and($err)->toContain('git archive cannot be built');
});

it('lists the development files of the archive, then fixes .gitattributes with --fix', function (): void {
    $cwd = harnessGitRepository([
        '.harness.json' => json_encode(['profile' => 'core']),
        'src/A.php' => "<?php\n",
        'tests/ATest.php' => "<?php\n",
        'phpunit.xml' => '<phpunit/>',
    ]);

    [$code, $out] = harnessRun(new ArchiveCommand, $cwd);
    expect($code)->toBe(1)->and($out)->toContain('would travel in the archive')->and($out)->toContain('export-ignore');

    [$fixed, $out] = harnessRun(new ArchiveCommand, $cwd, '--fix');
    expect($fixed)->toBe(0)->and($out)->toContain('+ ')->toContain('.gitattributes updated')
        ->and((string) file_get_contents($cwd.'/.gitattributes'))->toContain('export-ignore');

    [$clean, $out] = harnessRun(new ArchiveCommand, $cwd);
    expect($clean)->toBe(0)->and($out)->toContain('none for development');
});

it('refuses to check the surface before it is recorded', function (): void {
    $cwd = harnessTempDir();

    [$code, , $err] = harnessRun(new SurfaceCommand, $cwd);

    expect($code)->toBe(1)->and($err)->toContain('api-surface.json not found');
});

it('records, prints and compares the public surface', function (): void {
    $cwd = harnessTempDir();
    mkdir($cwd.'/src');
    harnessConfigure($cwd);
    file_put_contents($cwd.'/composer.json', '{"autoload": {"psr-4": {"Acme\\\\": "src/"}}}');
    file_put_contents($cwd.'/src/Greeter.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme;\n\nfunction fire(): void\n{\n    do_action('pollora/orders/placed');\n}\n");

    [$printed, $out] = harnessRun(new SurfaceCommand, $cwd, '--print');
    expect($printed)->toBe(0)->and($out)->toContain('hook:pollora/orders/placed');

    [$recorded, $out] = harnessRun(new SurfaceCommand, $cwd, '--update');
    expect($recorded)->toBe(0)->and($out)->toContain('api-surface.json recorded');

    [$same, $out] = harnessRun(new SurfaceCommand, $cwd);
    expect($same)->toBe(0)->and($out)->toContain('unchanged');

    file_put_contents($cwd.'/src/Extra.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme;\n\nfunction more(): void\n{\n    apply_filters('pollora/orders/total', 1);\n}\n");

    [$blocked, $out] = harnessRun(new SurfaceCommand, $cwd);
    expect($blocked)->toBe(1)->and($out)->toContain('QA-04')->toContain('api-change');

    [$allowed] = harnessRun(new SurfaceCommand, $cwd, '--allow=additions');
    expect($allowed)->toBe(0);
});

it('compares the surface without a .harness.json too', function (): void {
    $cwd = harnessTempDir();
    mkdir($cwd.'/src');
    file_put_contents($cwd.'/composer.json', '{"autoload": {"psr-4": {"Acme\\\\": "src/"}}}');
    file_put_contents($cwd.'/api-surface.json', '{"version": 1, "surface": {}}');

    [$code, $out] = harnessRun(new SurfaceCommand, $cwd);

    expect($code)->toBe(0)->and($out)->toContain('unchanged');
});

it('shows the ratchet, flags a regression and tightens only what improved', function (): void {
    $cwd = harnessTempDir();
    harnessConfigure($cwd, ['ratchet' => ['phpstan_level' => 5, 'phpstan_baseline' => 3, 'coverage' => 50.0]]);
    file_put_contents($cwd.'/phpstan.neon', "parameters:\n    level: 7\n");
    file_put_contents($cwd.'/phpstan-baseline.neon', "parameters:\n    ignoreErrors:\n        -\n            message: '#x#'\n            count: 5\n            path: a.php\n");

    [$code, $out] = harnessRun(new RatchetCommand, $cwd, '--metric=type_coverage:98.4', '--metric=nope:1', '--metric=coverage:abc');

    expect($code)->toBe(1)
        ->and($out)->toContain('regressed')->toContain('improved')
        ->and($out)->toContain('no Clover report found');

    file_put_contents($cwd.'/phpstan-baseline.neon', "parameters:\n    ignoreErrors: []\n");
    file_put_contents($cwd.'/clover.xml', '<coverage><project><metrics statements="10" coveredstatements="9"/></project></coverage>');

    [$ok, $out] = harnessRun(new RatchetCommand, $cwd, '--update', '--coverage=clover.xml');
    $saved = json_decode((string) file_get_contents($cwd.'/.harness.json'), true);

    expect($ok)->toBe(0)
        ->and($out)->toContain('.harness.json tightened.')
        ->and($saved['ratchet']['phpstan_level'])->toBe(7)
        ->and($saved['ratchet']['phpstan_baseline'])->toBe(0)
        ->and($saved['ratchet']['coverage'])->toEqual(90);

    [$unchanged, $out] = harnessRun(new RatchetCommand, $cwd, '--update', '--coverage=clover.xml');
    expect($unchanged)->toBe(0)->and($out)->not->toContain('tightened');
});

it('says an Infection log is missing when the ratchet records a score', function (): void {
    $cwd = harnessTempDir();
    harnessConfigure($cwd, ['ratchet' => ['msi' => 60.0]]);

    [, $out] = harnessRun(new RatchetCommand, $cwd);

    expect($out)->toContain('no Infection log given');
});

it('runs the fast gates and writes the report, the summary and the exit code', function (): void {
    $cwd = harnessTempDir();
    harnessConfigure($cwd, ['mode' => 'enforce']);
    file_put_contents($cwd.'/composer.json', '{"require": {"php": "^8.3", "laravel/framework": "^13.0"}}');
    putenv('GITHUB_STEP_SUMMARY='.$cwd.'/summary.md');

    [$code, $out] = harnessRun(new CheckCommand, $cwd, '--fast', '--no-ansi', '--report=out/report.json');
    $report = json_decode((string) file_get_contents($cwd.'/out/report.json'), true);

    expect($code)->toBe(1)
        ->and($out)->toContain('profile core · enforce mode · fast gates')->toContain('Some gates failed')
        ->and($report['failed'])->toBeTrue()
        ->and($report['gates'])->toBe('fast')
        ->and((string) file_get_contents($cwd.'/summary.md'))->toContain('### Pollora harness');

    [$observed] = harnessRun(new CheckCommand, $cwd, '--fast', '--observe');
    expect($observed)->toBe(0);

    [$enforced] = harnessRun(new CheckCommand, $cwd, '--fast', '--observe', '--enforce');
    expect($enforced)->toBe(1);
});

it('fails a missing tool only with --strict', function (): void {
    $cwd = harnessTempDir();
    harnessConfigure($cwd, ['mode' => 'enforce']);

    [$lenient, $out] = harnessRun(new CheckCommand, $cwd, '--fast');
    [$strict, $strictOut] = harnessRun(new CheckCommand, $cwd, '--fast', '--strict');

    expect($lenient)->toBe(0)->and($out)->toContain('tool is missing')
        ->and($strict)->toBe(1)->and($strictOut)->toContain('Strict mode');
});

it('checks the files changed in a git repository only', function (): void {
    $cwd = harnessGitRepository(['.harness.json' => json_encode(['profile' => 'core']), 'a.php' => "<?php\n"]);
    file_put_contents($cwd.'/a.php', "<?php\n\n// changed\n");

    [$code, $out] = harnessRun(new CheckCommand, $cwd, '--fast');

    expect($code)->toBe(0)->and($out)->not->toContain('git is not usable');
});

it('runs a command through the application and reports an invalid configuration', function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/.harness.json', '{"profile": "nonsense"}');

    $app = new Application([new DepsCommand]);

    expect($app->run(['pollora-qa', 'deps', '--no-ansi'], $cwd))->toBe(1);
});

it('runs a command through the application', function (): void {
    $cwd = harnessTempDir();
    harnessConfigure($cwd);
    file_put_contents($cwd.'/composer.json', '{"require": {"php": "^8.3"}}');

    expect((new Application([new DepsCommand]))->run(['pollora-qa', 'deps', '--no-ansi'], $cwd))->toBe(0);
});

it('answers help, help for one command and an unknown command', function (): void {
    $app = new Application;
    $cwd = harnessTempDir();

    expect($app->run(['pollora-qa'], $cwd))->toBe(0)
        ->and($app->run(['pollora-qa', 'help', 'check', '--no-ansi'], $cwd))->toBe(0)
        ->and($app->run(['pollora-qa', '--help', 'rules', '--no-ansi'], $cwd))->toBe(0)
        ->and($app->run(['pollora-qa', 'help', 'deps', '--no-ansi'], $cwd))->toBe(0)
        ->and($app->run(['pollora-qa', 'no-such-command', '--no-ansi'], $cwd))->toBe(1);
});

it('decorates its output only on a terminal that wants colours', function (): void {
    $stdout = fopen('php://memory', 'w+');
    $io = Io::fromArgv('/tmp', ['--no-ansi', 'x', '--metric=a:1,b:2', '--metric=c:3'], $stdout, $stdout);

    expect($io->arguments)->toBe(['x'])
        ->and($io->flag('no-ansi'))->toBeTrue()
        ->and($io->option('no-ansi'))->toBeNull()
        ->and($io->list('metric'))->toBe(['c:3'])
        ->and($io->list('absent'))->toBe([])
        ->and($io->success('ok'))->toBe('ok');

    $decorated = new Io('/tmp', [], [], $stdout, $stdout, true);
    $decorated->title('T');
    rewind($stdout);

    expect($decorated->failure('x'))->toBe("\033[31mx\033[0m")
        ->and($decorated->warning('x'))->toBe("\033[33mx\033[0m")
        ->and($decorated->muted('x'))->toBe("\033[2mx\033[0m")
        ->and((string) stream_get_contents($stdout))->toContain("\033[1mT\033[0m");
});

it('is a command that can be listed', function (): void {
    expect((new RulesCommand)->name())->toBe('rules')
        ->and((new RulesCommand)->usage())->toContain('--status')
        ->and(Command::BLOCK)->toBe(2);
});
