<?php

declare(strict_types=1);

namespace Pollora\Harness\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Pollora\Harness\PHPStan\WordPressFunctions;

/**
 * ARCH-02: code in a Domain namespace calls no global WordPress function.
 *
 * The domain is what makes Pollora portable: the same rules run inside
 * Laravel, inside a plain WordPress through the mu-plugin, and in unit tests
 * without WordPress. A call to add_action() or get_option() there ties the
 * domain to WordPress; it belongs behind a port, implemented in the
 * Infrastructure layer.
 *
 * @implements Rule<FuncCall>
 */
final readonly class NoWordPressCallInDomainRule implements Rule
{
    public const string IDENTIFIER = 'pollora.noWordPressCallInDomain';

    public function __construct(private WordPressFunctions $functions) {}

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name || ! self::isDomain($scope->getNamespace())) {
            return [];
        }

        // A namespaced call (Foo\bar()) keeps its namespace here and cannot
        // match: only global functions are WordPress's.
        $function = ltrim($node->name->toLowerString(), '\\');

        if (! $this->functions->contains($function)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('[ARCH-02] The domain calls the WordPress function %s().', $function))
                ->identifier(self::IDENTIFIER)
                ->tip('Declare a port (an interface) in the domain and implement it with this call in the Infrastructure layer.')
                ->build(),
        ];
    }

    public static function isDomain(?string $namespace): bool
    {
        return $namespace !== null && preg_match('/(^|\\\\)Domain(\\\\|$)/', $namespace) === 1;
    }
}
