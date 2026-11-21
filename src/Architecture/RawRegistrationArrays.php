<?php

declare(strict_types=1);

namespace Pollora\Harness\Architecture;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * CONV-02: a WordPress registration is described by an attribute or a typed object, never by a raw
 * argument array in the public API.
 *
 * What is reported: a public method (or a global function) with a parameter that can hold an array, when
 * that array flows into the arguments that describe a registration: the `$args` of `register_post_type()`,
 * the `$args` of `register_taxonomy()`, the `$args` of `register_block_type()` and
 * `register_block_type_from_metadata()`, the `$args` of `register_rest_route()`, and any argument of
 * `add_menu_page()` / `add_submenu_page()`. The caller of such a method describes a post type, a taxonomy,
 * a block, a route or a menu page with an untyped array: that is the API the rule rules out.
 *
 * The flow is followed inside the method only: the parameter itself, an element of it (`$args['label']`),
 * an expression that uses it (`array_merge($defaults, $args)`, `$args + $defaults`, a spread) and a local
 * variable assigned from such an expression, also from within a closure of the method (the usual
 * `add_action('init', function () use ($args) { register_post_type($name, $args); })`).
 *
 * What is NOT reported, on purpose:
 * - the infrastructure layer (a class under an `Infrastructure` or `Adapter` namespace): it is where
 *   WordPress is called, so the array is unavoidable there; the rule's point is that a typed object builds
 *   it (`PostType::toArgs()`) and the public API, above that layer, never exposes it;
 * - a method that is not public, a class marked `@internal`, a method marked `@internal`;
 * - a literal array built inside the method (`register_post_type('book', ['label' => 'Books'])`): its
 *   public signature takes no array, so the caller does not describe anything with one;
 * - a parameter typed as something other than an array (`PostType $type`, `iterable`, `string`);
 * - an interface or abstract method, which has no body to follow.
 *
 * What cannot be seen (a false negative, accepted and documented): an array that is stored and registered
 * by another method (`$this->args = $args;` then `register_post_type(…, $this->args)`), a registration
 * through a wrapper (`$this->wp->registerPostType($args)`), a function file listed in `autoload.files`
 * (only the PSR-4 classes are read), and a file that does not parse, which is listed as a problem of its own
 * so that nothing is skipped silently.
 *
 * Read with nikic/php-parser, so it judges a repository whose dependencies are not installed.
 */
final class RawRegistrationArrays
{
    /**
     * WordPress registration functions, and the positions of the arguments that describe the registration
     * (null: every argument, for the menu functions that take scalars and no description array).
     *
     * @var array<string, list<int>|null>
     */
    public const array FUNCTIONS = [
        'register_post_type' => [1],
        'register_taxonomy' => [2],
        'register_block_type' => [1],
        'register_block_type_from_metadata' => [1],
        'register_rest_route' => [2],
        'add_menu_page' => null,
        'add_submenu_page' => null,
    ];

    /** The names of the parameters that carry a registration's arguments, for the signature test. */
    private const array ARGUMENT_NAMES = ['args', 'arguments', 'config', 'options', 'settings'];

    /** Method names that register or build a registration. */
    private const string VERBS = '/^(register|create|define|add)/i';

    /** What the signature test calls a WordPress registration; the namespace, the class or the method must say it. */
    private const string NOUNS = '/(PostType|Taxonomy|Block|Route|Menu)/';

    /** Namespace segments of the layer that calls WordPress: the raw array is expected there. */
    private const array INFRASTRUCTURE = ['Infrastructure', 'Adapter'];

    /**
     * @param  string  $root  The repository, to print paths relative to it
     * @return list<string> One line per public method that takes a raw array to register something
     */
    public static function problems(Layers $layers, string $root = ''): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $problems = [];

        foreach ($layers->files as $class => $path) {
            if (self::isInfrastructure($class)) {
                continue;
            }

            $shown = $root !== '' && str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : $path;
            array_push($problems, ...self::problemsOfFile($parser, $path, $shown, $class));
        }

        sort($problems, SORT_NATURAL);

        return array_values(array_unique($problems));
    }

    /**
     * Whether a class sits in the layer that calls WordPress.
     */
    public static function isInfrastructure(string $class): bool
    {
        return array_intersect(explode('\\', $class), self::INFRASTRUCTURE) !== [];
    }

    /**
     * @return list<string>
     */
    private static function problemsOfFile(Parser $parser, string $path, string $shown, string $fqcn): array
    {
        $code = (string) file_get_contents($path);
        $code = preg_replace('/%[A-Za-z_][A-Za-z0-9_]*%/', 'Placeholder', $code) ?? $code;

        try {
            $ast = $parser->parse($code) ?? [];
        } catch (Throwable) {
            return ["{$shown} could not be parsed: not judged."];
        }

        $finder = new NodeFinder;
        $problems = [];

        foreach ($finder->findInstanceOf($ast, ClassLike::class) as $class) {
            if (self::isInternal($class)) {
                continue;
            }

            foreach ($class->getMethods() as $method) {
                if (! $method->isPublic() || self::isInternal($method)) {
                    continue;
                }

                $where = "{$shown}:{$method->getStartLine()}";
                $name = ($class->name?->toString() ?? 'class@anonymous').'::'.$method->name->toString();
                $flows = self::problemsOfFunction($finder, $method, $where, $name);

                array_push($problems, ...($flows !== [] ? $flows : self::problemsOfSignature($method, $where, $name, $fqcn)));
            }
        }

        foreach ($finder->findInstanceOf($ast, Function_::class) as $function) {
            if (! self::isInternal($function)) {
                array_push($problems, ...self::problemsOfFunction($finder, $function, "{$shown}:{$function->getStartLine()}", $function->name->toString()));
            }
        }

        return $problems;
    }

    /**
     * The signature test, for what the flow cannot follow (an interface method has no body, and a service
     * hands the array to a registry that calls WordPress): a public method named `register…`, `create…`,
     * `define…` or `add…`, about a post type, a taxonomy, a block, a REST route or a menu page (named so by
     * its namespace, its class or itself), with a parameter named `$args`, `$arguments`, `$config`,
     * `$options` or `$settings` that can hold an array.
     *
     * @return list<string>
     */
    private static function problemsOfSignature(ClassMethod $method, string $where, string $name, string $fqcn): array
    {
        if (preg_match(self::VERBS, $method->name->toString()) !== 1 || preg_match(self::NOUNS, $fqcn.'::'.$method->name->toString(), $noun) !== 1) {
            return [];
        }

        foreach ($method->params as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name) && in_array($param->var->name, self::ARGUMENT_NAMES, true) && self::canHoldArray($param->type)) {
                return ["{$where} {$name}() takes a raw array (\${$param->var->name}) to describe a registration ({$noun[1]}): use an attribute or a typed object."];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function problemsOfFunction(NodeFinder $finder, ClassMethod|Function_ $function, string $where, string $name): array
    {
        $tainted = self::arrayParameters($function->params);

        if ($tainted === []) {
            return [];
        }

        $statements = $function->stmts ?? [];
        $tainted = self::propagate($finder, $statements, $tainted);
        $problems = [];

        /** @var list<FuncCall> $calls */
        $calls = $finder->findInstanceOf($statements, FuncCall::class);

        foreach ($calls as $call) {
            $registration = self::registrationFunction($call);

            if ($registration === null) {
                continue;
            }

            $positions = self::FUNCTIONS[$registration];

            foreach ($call->args as $position => $argument) {
                if (($positions === null || in_array($position, $positions, true)) && $argument instanceof Node\Arg && self::usesAny($finder, $argument->value, $tainted)) {
                    $parameters = implode(', ', array_map(static fn (string $parameter): string => '$'.$parameter, self::arrayParameters($function->params)));
                    $problems[] = "{$where} {$name}() passes its array parameter ({$parameters}) to {$registration}(): describe the registration with an attribute or a typed object, not a raw array.";

                    break;
                }
            }
        }

        return $problems;
    }

    /**
     * The names of the parameters that can hold an array: typed `array` (alone, nullable or in a union),
     * or without a type, which accepts one as well.
     *
     * @param  array<Param>  $params
     * @return list<string>
     */
    private static function arrayParameters(array $params): array
    {
        $names = [];

        foreach ($params as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name) && self::canHoldArray($param->type)) {
                $names[] = $param->var->name;
            }
        }

        return $names;
    }

    /**
     * Whether a declared type accepts an array: absent, `array`, `?array`, or a union with `array` or `mixed`.
     */
    private static function canHoldArray(?Node $type): bool
    {
        if (! $type instanceof Node) {
            return true;
        }

        if ($type instanceof NullableType) {
            return self::canHoldArray($type->type);
        }

        if ($type instanceof UnionType) {
            foreach ($type->types as $member) {
                if (! $member instanceof IntersectionType && self::canHoldArray($member)) {
                    return true;
                }
            }

            return false;
        }

        return $type instanceof Identifier && in_array($type->toLowerString(), ['array', 'mixed'], true);
    }

    /**
     * Adds to the tainted names every local variable assigned from an expression that uses one of them,
     * until nothing changes (`$merged = array_merge($defaults, $args); $final = $merged;`).
     *
     * @param  array<Node>  $statements
     * @param  list<string>  $tainted
     * @return list<string>
     */
    private static function propagate(NodeFinder $finder, array $statements, array $tainted): array
    {
        $assignments = [...$finder->findInstanceOf($statements, Assign::class), ...$finder->findInstanceOf($statements, AssignOp::class)];

        do {
            $changed = false;

            foreach ($assignments as $assignment) {
                $target = $assignment->var;

                if ($target instanceof Variable && is_string($target->name) && ! in_array($target->name, $tainted, true) && self::usesAny($finder, $assignment->expr, $tainted)) {
                    $tainted[] = $target->name;
                    $changed = true;
                }
            }
        } while ($changed);

        return $tainted;
    }

    /**
     * Whether an expression reads one of the variables.
     *
     * @param  list<string>  $names
     */
    private static function usesAny(NodeFinder $finder, Expr $expression, array $names): bool
    {
        foreach ($finder->findInstanceOf($expression, Variable::class) as $variable) {
            if (is_string($variable->name) && in_array($variable->name, $names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The registration function a call names (`register_post_type()` or `\register_post_type()`), or null.
     */
    private static function registrationFunction(FuncCall $call): ?string
    {
        if (! $call->name instanceof Name) {
            return null;
        }

        $name = strtolower($call->name->getLast());

        return array_key_exists($name, self::FUNCTIONS) && ($call->name->isUnqualified() || $call->name->isFullyQualified()) ? $name : null;
    }

    /**
     * Whether the doc comment of a node carries `@internal`: not part of the public API.
     */
    private static function isInternal(Node $node): bool
    {
        $doc = $node->getDocComment();

        return $doc instanceof Doc && preg_match('/@internal\b/', $doc->getText()) === 1;
    }
}
