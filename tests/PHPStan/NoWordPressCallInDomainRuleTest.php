<?php

declare(strict_types=1);

namespace Pollora\Harness\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Pollora\Harness\PHPStan\Rules\NoWordPressCallInDomainRule;
use Pollora\Harness\PHPStan\WordPressFunctions;

/**
 * @extends RuleTestCase<NoWordPressCallInDomainRule>
 */
final class NoWordPressCallInDomainRuleTest extends RuleTestCase
{
    private const TIP = 'Declare a port (an interface) in the domain and implement it with this call in the Infrastructure layer.';

    public function test_it_reports_wordpress_calls_in_the_domain_only(): void
    {
        $this->analyse([__DIR__.'/../Fixtures/phpstan/Domain/Greeter.php'], [
            ['[ARCH-02] The domain calls the WordPress function add_action().', 11, self::TIP],
            ['[ARCH-02] The domain calls the WordPress function get_option().', 12, self::TIP],
        ]);
    }

    public function test_it_leaves_the_infrastructure_alone(): void
    {
        $this->analyse([__DIR__.'/../Fixtures/phpstan/Infrastructure/Registrar.php'], []);
    }

    public function test_it_reads_the_function_list_from_the_wordpress_stubs(): void
    {
        $functions = new WordPressFunctions;

        self::assertTrue($functions->contains('wp_insert_post'));
        self::assertTrue($functions->contains('Register_Post_Type'));
        self::assertFalse($functions->contains('array_map'));
        // WP_Query::reset() is a method in the stubs, and reset() a PHP function.
        self::assertFalse($functions->contains('reset'));
        self::assertGreaterThan(1000, count($functions->names()));
    }

    protected function getRule(): Rule
    {
        return new NoWordPressCallInDomainRule(new WordPressFunctions);
    }
}
