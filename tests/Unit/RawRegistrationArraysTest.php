<?php

declare(strict_types=1);

use Pollora\Harness\Architecture\Layers;
use Pollora\Harness\Architecture\RawRegistrationArrays;

if (! function_exists('rawArraysProject')) {
    /**
     * A repository (namespace Acme\Raw) whose files are given by relative path, and its CONV-02 problems.
     *
     * @param  array<string, string>  $files  Path under the project => PHP source (the opening tag is added)
     * @return list<string>
     */
    function rawArraysProject(array $files): array
    {
        $root = harnessTempDir();

        foreach ($files as $path => $source) {
            is_dir(dirname("{$root}/{$path}")) || mkdir(dirname("{$root}/{$path}"), 0o755, true);
            file_put_contents("{$root}/{$path}", "<?php\n\ndeclare(strict_types=1);\n\n{$source}\n");
        }

        file_put_contents($root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['Acme\\Raw\\' => 'src/']]], JSON_UNESCAPED_SLASHES));

        return RawRegistrationArrays::problems(Layers::discover($root), $root);
    }
}

it('reports a public method that hands its array parameter to a registration function (CONV-02)', function (string $call, string $function): void {
    $problems = rawArraysProject([
        'src/Registry.php' => "namespace Acme\\Raw;\n\nfinal class Registry\n{\n    public function register(string \$name, array \$args = []): void\n    {\n        {$call}\n    }\n}",
    ]);

    expect($problems)->toBe(["src/Registry.php:9 Registry::register() passes its array parameter (\$args) to {$function}(): describe the registration with an attribute or a typed object, not a raw array."]);
})->with([
    'post type' => ['register_post_type($name, $args);', 'register_post_type'],
    'taxonomy' => ['register_taxonomy($name, "post", $args);', 'register_taxonomy'],
    'block' => ['register_block_type($name, $args);', 'register_block_type'],
    'block metadata' => ['register_block_type_from_metadata($name, $args);', 'register_block_type_from_metadata'],
    'rest route' => ['register_rest_route("a/v1", $name, $args);', 'register_rest_route'],
    'menu page' => ['add_menu_page("t", "m", "manage_options", $name, null, "", $args["position"]);', 'add_menu_page'],
    'submenu page' => ['add_submenu_page("p", "t", "m", "manage_options", $args["slug"]);', 'add_submenu_page'],
    'fully qualified' => ['\\register_post_type($name, $args);', 'register_post_type'],
    'merged with defaults' => ['register_post_type($name, array_merge(["public" => true], $args));', 'register_post_type'],
    'through a local variable' => ['$final = $args + ["public" => true]; $again = $final; register_post_type($name, $again);', 'register_post_type'],
    'inside a hook closure' => ['add_action("init", function () use ($name, $args): void { register_post_type($name, $args); });', 'register_post_type'],
]);

it('reports an untyped, nullable or union parameter, and a global function', function (): void {
    $problems = rawArraysProject([
        'src/Loose.php' => "namespace Acme\\Raw;\n\nfinal class Loose\n{\n    public function a(\$args): void\n    {\n        register_post_type('a', \$args);\n    }\n\n    public function b(?array \$args): void\n    {\n        register_post_type('b', \$args);\n    }\n\n    public function c(array|string \$args): void\n    {\n        register_post_type('c', \$args);\n    }\n}\n\nfunction describe(array \$args): void\n{\n    register_post_type('d', \$args);\n}",
    ]);

    expect($problems)->toHaveCount(4)
        ->and($problems[0])->toContain('Loose::a()')
        ->and($problems[3])->toContain('describe()');
});

it('leaves alone what the rule does not forbid (CONV-02)', function (): void {
    $problems = rawArraysProject([
        'src/Infrastructure/Wp.php' => "namespace Acme\\Raw\\Infrastructure;\n\nfinal class Wp\n{\n    public function register(string \$name, array \$args): void\n    {\n        register_post_type(\$name, \$args);\n    }\n}",
        'src/Adapter/Wp.php' => "namespace Acme\\Raw\\Adapter;\n\nfinal class Wp\n{\n    public function register(string \$name, array \$args): void\n    {\n        register_post_type(\$name, \$args);\n    }\n}",
        'src/Typed.php' => "namespace Acme\\Raw;\n\nfinal class Typed\n{\n    public function register(PostType \$type, string \$label, iterable \$more): void\n    {\n        register_post_type(\$type->name, \$type->toArgs());\n        register_post_type(\$label, \$more);\n    }\n}",
        'src/Literal.php' => "namespace Acme\\Raw;\n\nfinal class Literal\n{\n    public function register(array \$names): void\n    {\n        register_post_type('book', ['label' => 'Books']);\n        foreach (\$names as \$slug) {\n            register_post_type(\$slug, ['public' => true]);\n        }\n        register_post_type('x', ['y' => \$names[0]]);\n    }\n}",
        'src/Hidden.php' => "namespace Acme\\Raw;\n\nfinal class Hidden\n{\n    protected function a(array \$args): void\n    {\n        register_post_type('a', \$args);\n    }\n\n    private function b(array \$args): void\n    {\n        register_post_type('b', \$args);\n    }\n\n    /** @internal */\n    public function c(array \$args): void\n    {\n        register_post_type('c', \$args);\n    }\n}",
        'src/Internal.php' => "namespace Acme\\Raw;\n\n/** @internal */\nfinal class Internal\n{\n    public function a(array \$args): void\n    {\n        register_post_type('a', \$args);\n    }\n}",
        'src/Contract.php' => "namespace Acme\\Raw;\n\ninterface Contract\n{\n    public function register(array \$args): void;\n}",
        'src/Other.php' => "namespace Acme\\Raw;\n\nfinal class Other\n{\n    public function a(array \$args): void\n    {\n        Other::register_post_type('a', \$args);\n        \$this->register_post_type('a', \$args);\n        my_register_post_type('a', \$args);\n        register_post_type_helper('a', \$args);\n    }\n}",
    ]);

    // The literal array of Literal::register() reads an element of its parameter ($names[0]) into a description:
    // that one is reported, the three others are not.
    expect($problems)->toBe(['src/Literal.php:9 Literal::register() passes its array parameter ($names) to register_post_type(): describe the registration with an attribute or a typed object, not a raw array.']);
});

it('lists a file that does not parse instead of skipping it', function (): void {
    expect(rawArraysProject(['src/Broken.php' => "namespace Acme\\Raw;\n\nfinal class Broken {"]))->toBe(['src/Broken.php could not be parsed: not judged.']);
});

it('reads a template with %placeholders% as PHP', function (): void {
    $problems = rawArraysProject([
        'src/Template.php' => "namespace Acme\\Raw\\%name%;\n\nfinal class Template\n{\n    public function register(array \$args): void\n    {\n        register_post_type('a', \$args);\n    }\n}",
    ]);

    expect($problems)->toHaveCount(1);
});

it('reports the signature of a contract or a service that takes raw registration arguments', function (): void {
    $problems = rawArraysProject([
        'src/Contracts/PostTypeService.php' => "namespace Acme\\Raw\\Contracts;\n\ninterface PostTypeService\n{\n    public function register(string \$slug, array \$args = []): object;\n}",
        'src/TaxonomyService.php' => "namespace Acme\\Raw;\n\nfinal class TaxonomyService\n{\n    public function create(string \$slug, \$options): void {}\n}",
        'src/Registrar.php' => "namespace Acme\\Raw;\n\nfinal class Registrar\n{\n    public function registerBlock(array \$config): void {}\n\n    public function registerBlockPattern(PatternDefinition \$definition): void {}\n}",
        'src/Cache.php' => "namespace Acme\\Raw;\n\nfinal class Cache\n{\n    public function create(array \$config): void {}\n\n    public function loadPostType(array \$args): void {}\n}",
    ]);

    expect($problems)->toHaveCount(3)
        ->and($problems[0])->toContain('src/Contracts/PostTypeService.php:9 PostTypeService::register() takes a raw array ($args)')
        ->and($problems[1])->toContain('Registrar::registerBlock() takes a raw array ($config)')
        ->and($problems[2])->toContain('TaxonomyService::create() takes a raw array ($options)');
});
