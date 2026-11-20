<?php

declare(strict_types=1);

namespace Pollora\Harness\Metrics;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects the typed and untyped declarations of one syntax tree.
 *
 * @internal Used by {@see TypeCoverage}
 */
final class TypeCoverageVisitor extends NodeVisitorAbstract
{
    /** @var array{return: array{int, int}, parameter: array{int, int}, property: array{int, int}} */
    public array $stats = ['return' => [0, 0], 'parameter' => [0, 0], 'property' => [0, 0]];

    /** Declarations without a type, all kinds together. */
    public int $untyped = 0;

    /** Parameters left out because a WordPress parent forbids typing them. */
    public int $excluded = 0;

    /**
     * Per class being visited, the methods whose parameters cannot be typed.
     *
     * @var list<list<string>>
     */
    private array $overrides = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->overrides[] = self::untypableMethods($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Interface_ || $node instanceof Node\Stmt\Trait_ || $node instanceof Node\Stmt\Enum_) {
            $this->overrides[] = [];

            return null;
        }

        if ($node instanceof Node\Stmt\Property) {
            foreach ($node->props as $_) {
                $this->count('property', $node->type instanceof Node);
            }

            return null;
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            $name = $node->name->toLowerString();
            $isMagicWithoutReturn = $name === '__construct' || $name === '__destruct';
            $untypable = in_array($name, $this->overrides[count($this->overrides) - 1] ?? [], true);

            if (! $isMagicWithoutReturn) {
                $this->count('return', $node->returnType instanceof Node);
            }

            $this->parameters($node->params, $untypable);

            return null;
        }

        if ($node instanceof Node\Stmt\Function_) {
            $this->count('return', $node->returnType instanceof Node);
            $this->parameters($node->params, false);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->overrides);
        }

        return null;
    }

    /**
     * @param  array<Node\Param>  $params
     */
    private function parameters(array $params, bool $untypable): void
    {
        foreach ($params as $param) {
            if ($untypable && $param->type === null) {
                $this->excluded++;

                continue;
            }

            $this->count('parameter', $param->type instanceof Node);
        }
    }

    /**
     * @param  'return'|'parameter'|'property'  $kind
     */
    private function count(string $kind, bool $typed): void
    {
        $this->stats[$kind][1]++;

        if ($typed) {
            $this->stats[$kind][0]++;
        } else {
            $this->untyped++;
        }
    }

    /**
     * The lowercase names of the methods of $class that override an untyped
     * method of WordPress, or none when its parent is not one of those.
     *
     * @return list<string>
     */
    private static function untypableMethods(Node\Stmt\Class_ $class): array
    {
        $parent = $class->extends?->toString();

        if ($parent === null || str_contains($parent, '\\')) {
            return [];
        }

        foreach (TypeCoverage::OVERRIDES as $pattern => $methods) {
            if (preg_match($pattern, $parent) === 1) {
                return $methods;
            }
        }

        return [];
    }
}
