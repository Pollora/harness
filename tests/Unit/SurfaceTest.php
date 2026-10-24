<?php

declare(strict_types=1);

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
