<?php

declare(strict_types=1);

use Pollora\Harness\Config\CommandExemption;
use Pollora\Harness\Surface\NamingConventions;
use Pollora\Harness\Surface\SurfaceDiff;
use Pollora\Harness\Surface\SurfaceExtractor;

function fixtureSurface(): array
{
    return (new SurfaceExtractor(fixtureProject()))->extract();
}

it('records each attribute with its targets and constructor parameters', function (): void {
    $good = 'attribute:Pollora\\Harness\\Tests\\Fixtures\\Project\\Attributes\\GoodAttribute';
    $open = 'attribute:Pollora\\Harness\\Tests\\Fixtures\\Project\\Attributes\\OpenAttribute';

    expect(fixtureSurface())
        ->toHaveKey($good, 'targets class')
        ->toHaveKey($good.'($name)', 'string, required')
        ->toHaveKey($open, 'targets class, function, method, property, constant, parameter')
        ->toHaveKey($open.'($priority)', 'int, optional = 10');
});

it('records the hooks Pollora fires, as actions and filters when the call says so', function (): void {
    expect(fixtureSurface())
        ->toHaveKey('hook:pollora/orders/placed', 'action')
        ->toHaveKey('hook:pollora/orders/total', 'filter')
        ->toHaveKey('hook:pollora/orders/status_label', 'hook')
        ->toHaveKey('hook:pollora/order_placed', 'action')
        ->not->toHaveKey('hook:pollora/framework');
});

it('records the configuration keys, not the items of a list', function (): void {
    $keys = array_keys(array_filter(fixtureSurface(), static fn (string $key): bool => str_starts_with($key, 'config:'), ARRAY_FILTER_USE_KEY));

    expect($keys)->toBe([
        'config:orders.channels',
        'config:orders.currency',
        'config:orders.statuses',
        'config:orders.statuses.paid',
        'config:orders.statuses.paid.label',
        'config:orders.statuses.refunded',
        'config:orders.statuses.refunded.label',
    ]);
});

it('records the command names', function (): void {
    expect(fixtureSurface())
        ->toHaveKey('command:pollora:orders:sync')
        ->toHaveKey('command:orders:export');
});

it('flags the names that do not follow the conventions (CONV-04, CONV-06)', function (): void {
    expect(NamingConventions::problems(fixtureSurface()))->toBe([
        '[CONV-06] The command orders:export should start with pollora:.',
        '[CONV-04] The hook pollora/order_placed should read pollora/<module>/<event_snake_case>.',
    ]);
});

it('asks for the label each change needs', function (array $recorded, array $current, ?string $label): void {
    expect(SurfaceDiff::between($recorded, $current)->requiredLabel())->toBe($label);
})->with([
    'nothing changes' => [['hook:pollora/a/b' => 'action'], ['hook:pollora/a/b' => 'action'], null],
    'a hook is added' => [[], ['hook:pollora/a/b' => 'action'], 'api-change'],
    'a hook is removed' => [['hook:pollora/a/b' => 'action'], [], 'breaking'],
    'a parameter type changes' => [['attribute:A($x)' => 'string, required'], ['attribute:A($x)' => 'int, required'], 'breaking'],
    'an optional parameter is added' => [['attribute:A' => 'targets class'], ['attribute:A' => 'targets class', 'attribute:A($y)' => 'int, optional = 1'], 'api-change'],
    'a required parameter is added' => [['attribute:A' => 'targets class'], ['attribute:A' => 'targets class', 'attribute:A($y)' => 'int, required'], 'breaking'],
    'a new attribute has a required parameter' => [[], ['attribute:B' => 'targets class', 'attribute:B($y)' => 'int, required'], 'api-change'],
]);

it('lets a pull request through only with the label its change needs', function (string $label, string $allow, bool $allowed): void {
    $diff = match ($label) {
        'api-change' => SurfaceDiff::between([], ['hook:pollora/a/b' => 'action']),
        'breaking' => SurfaceDiff::between(['hook:pollora/a/b' => 'action'], []),
    };

    expect($diff->allowedBy($allow))->toBe($allowed);
})->with([
    ['api-change', 'none', false],
    ['api-change', 'additions', true],
    ['breaking', 'additions', false],
    ['breaking', 'all', true],
]);

it('records the Symfony Console commands of a package, however they are named', function (): void {
    $root = harnessTempDir();
    mkdir($root.'/src/Commands', 0o755, true);
    file_put_contents($root.'/composer.json', '{"name": "pollora/cli", "autoload": {"psr-4": {"Pollora\\\\Cli\\\\": "src/"}}}');

    $commands = [
        'New' => "use Symfony\\Component\\Console\\Command\\Command;\nclass NewCommand extends Command\n{\n    protected function configure(): void\n    {\n        \$this\n            ->setName('new')\n            ->setDescription('Create');\n    }\n}",
        'Legacy' => "use Symfony\\Component\\Console\\Command\\Command;\nclass LegacyCommand extends Command\n{\n    protected static \$defaultName = 'self-update';\n}",
        'Typed' => "use Symfony\\Component\\Console\\Command\\Command;\nclass TypedCommand extends Command\n{\n    protected static ?string \$defaultName = 'pollora:typed';\n}",
        'Attribute' => "use Symfony\\Component\\Console\\Attribute\\AsCommand;\n#[AsCommand(description: 'Does it', name: 'pollora:attr')]\nclass AttributeCommand {}",
        'Positional' => "use Symfony\\Component\\Console\\Attribute\\AsCommand;\n#[AsCommand('version')]\nclass PositionalCommand {}",
        'Other' => "class Theme\n{\n    public function build(): void\n    {\n        \$this->builder->setName('not-a-command');\n    }\n}",
    ];

    foreach ($commands as $name => $body) {
        file_put_contents("{$root}/src/Commands/{$name}.php", "<?php\n\nnamespace Pollora\\Cli\\Commands;\n\n{$body}\n");
    }

    $listed = array_keys(array_filter((new SurfaceExtractor($root))->extract(), static fn (string $key): bool => str_starts_with($key, 'command:'), ARRAY_FILTER_USE_KEY));

    expect($listed)->toBe(['command:new', 'command:pollora:attr', 'command:pollora:typed', 'command:self-update', 'command:version']);
});

it('lets a command keep its name when .harness.json says why, and still flags another (CONV-06)', function (): void {
    $surface = fixtureSurface() + ['command:new' => 'command', 'command:nectar:mcp' => 'command'];
    $exemptions = [
        new CommandExemption('orders:export', false, 'named for the tool'),
        new CommandExemption('nectar:', true, 'the MCP server is the product name'),
    ];

    expect(NamingConventions::problems($surface, $exemptions))->toBe([
        '[CONV-04] The hook pollora/order_placed should read pollora/<module>/<event_snake_case>.',
        '[CONV-06] The command new should start with pollora:.',
    ])->and(NamingConventions::exempted($surface, $exemptions))->toBe([
        '[CONV-06] The command orders:export keeps its name (allow_commands: named for the tool).',
        '[CONV-06] The command nectar:mcp keeps its name (allow_commands: the MCP server is the product name).',
    ]);
});

it('matches a command exactly, and a prefix at the start only', function (): void {
    $exact = new CommandExemption('new', false, 'x');
    $prefix = new CommandExemption('nectar:', true, 'x');

    expect($exact->matches('new'))->toBeTrue()
        ->and($exact->matches('new:thing'))->toBeFalse()
        ->and($prefix->matches('nectar:mcp'))->toBeTrue()
        ->and($prefix->matches('my-nectar:mcp'))->toBeFalse();
});
