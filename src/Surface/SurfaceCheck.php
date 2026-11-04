<?php

declare(strict_types=1);

namespace Pollora\Harness\Surface;

use Pollora\Harness\Config\CommandExemption;

/**
 * Compares the code with api-surface.json, for `pollora-qa surface` and for
 * the public surface gate of `pollora-qa check`.
 */
final readonly class SurfaceCheck
{
    /** What a pull request may change when nothing says otherwise. */
    public const string ENVIRONMENT = 'HARNESS_SURFACE_ALLOW';

    /**
     * @param  list<CommandExemption>  $commandExemptions  Commands that keep a name without the pollora: prefix (CONV-06)
     */
    public function __construct(private string $root, private array $commandExemptions = []) {}

    /**
     * The changes a pull request declares, from its labels in CI:
     * "none", "additions" (api-change) or "all" (breaking).
     */
    public static function allowance(?string $option): string
    {
        $allow = $option ?? (string) getenv(self::ENVIRONMENT);

        return in_array($allow, ['additions', 'all'], true) ? $allow : 'none';
    }

    /**
     * @return array{passed: bool, lines: list<string>}
     */
    public function run(string $allow): array
    {
        $current = (new SurfaceExtractor($this->root))->extract();
        $diff = SurfaceDiff::between(SurfaceFile::read($this->root), $current);
        $lines = array_map(static fn (string $line): string => '  '.$line, $diff->lines());

        foreach ([...NamingConventions::problems($current, $this->commandExemptions), ...NamingConventions::exempted($current, $this->commandExemptions)] as $problem) {
            $lines[] = '  '.$problem;
        }

        if ($diff->isEmpty()) {
            array_unshift($lines, sprintf('  The public surface is unchanged (%d entries).', count($current)));

            return ['passed' => true, 'lines' => $lines];
        }

        $label = $diff->requiredLabel();
        $passed = $diff->allowedBy($allow);

        $lines[] = '';
        $lines[] = $passed
            ? "  [QA-04] The public surface changes, and the pull request says so ({$label}). Record it: vendor/bin/pollora-qa surface --update."
            : "  [QA-04] The public surface changes: label the pull request \"{$label}\"".($label === 'breaking' ? ', deprecate first and release it in a major version' : '').', then record it with vendor/bin/pollora-qa surface --update.';

        return ['passed' => $passed, 'lines' => $lines];
    }
}
