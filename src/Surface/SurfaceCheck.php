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
     * Compares the code with the recorded surface and checks the names of its hooks and commands.
     *
     * The check fails when the surface changes beyond what $allow permits (QA-04), and also when a hook or a command
     * breaks the naming conventions (CONV-04, CONV-06), whether or not the surface changed. A command that
     * .harness.json exempts is only reported.
     *
     * @return array{passed: bool, lines: list<string>}
     */
    public function run(string $allow): array
    {
        $current = (new SurfaceExtractor($this->root))->extract();
        $diff = SurfaceDiff::between(SurfaceFile::read($this->root), $current);
        $lines = array_map(static fn (string $line): string => '  '.$line, $diff->lines());

        $problems = NamingConventions::problems($current, $this->commandExemptions);

        foreach ([...$problems, ...NamingConventions::exempted($current, $this->commandExemptions)] as $problem) {
            $lines[] = '  '.$problem;
        }

        $named = $problems === [] ? null : '  [CONV-04, CONV-06] A name above does not follow the convention: rename it (an alias and a deprecation for a released name), or list a command under allow_commands in .harness.json with the reason.';

        if ($diff->isEmpty()) {
            array_unshift($lines, sprintf('  The public surface is unchanged (%d entries).', count($current)));

            if ($named !== null) {
                $lines[] = '';
                $lines[] = $named;
            }

            return ['passed' => $named === null, 'lines' => $lines];
        }

        $label = $diff->requiredLabel();
        $allowed = $diff->allowedBy($allow);

        $lines[] = '';
        $lines[] = $allowed
            ? "  [QA-04] The public surface changes, and the pull request says so ({$label}). Record it: vendor/bin/pollora-qa surface --update."
            : "  [QA-04] The public surface changes: label the pull request \"{$label}\"".($label === 'breaking' ? ', deprecate first and release it in a major version' : '').', then record it with vendor/bin/pollora-qa surface --update.';

        if ($named !== null) {
            $lines[] = $named;
        }

        return ['passed' => $allowed && $named === null, 'lines' => $lines];
    }
}
