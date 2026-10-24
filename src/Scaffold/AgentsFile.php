<?php

declare(strict_types=1);

namespace Pollora\Harness\Scaffold;

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Rules\Catalogue;

/**
 * Writes the harness block of AGENTS.md: what a coding agent needs to pass
 * the gates on the first try. The block sits between two markers, so the
 * rest of the file stays the repository's own and a regeneration only
 * replaces the block.
 */
final class AgentsFile
{
    public const START = '<!-- pollora-harness:start -->';

    public const END = '<!-- pollora-harness:end -->';

    public static function block(HarnessConfig $config): string
    {
        $rules = [];

        foreach (Catalogue::all() as $id => $rule) {
            if ($rule['status'] !== 'planned') {
                $rules[] = "- **{$id}** {$rule['title']} _({$rule['tool']}, {$rule['gate']})_";
            }
        }

        $protected = implode(', ', array_map(static fn (string $path): string => "`{$path}`", $config->protected));
        $profile = $config->profile->value;
        $mode = $config->mode->value;
        $list = implode("\n", $rules);
        $start = self::START;
        $end = self::END;

        return <<<MD
            {$start}
            ## Pollora harness

            This repository follows the Pollora harness (profile `{$profile}`, {$mode} mode). The same gates run on your machine, in agent hooks and in CI; a pull request merges only when they pass.

            ### Before you hand back

            - `composer qa:fast` runs the fast gates on what you changed (style, static analysis, architecture, dependencies). It must pass.
            - `composer qa` runs every gate, as CI does.
            - `vendor/bin/pollora-qa rules <ID>` explains a rule quoted in a failure.

            ### Never

            - Never edit {$protected}: they hold the thresholds. If one is wrong, stop and explain why to the user.
            - Never add `@phpstan-ignore`, a baseline entry or a `nosemgrep` comment without a written reason on the same line.
            - Never weaken a test to make it pass, and never mark a test skipped to get a green run.
            - Never break the public API (attributes, hooks fired, configuration keys, commands) in a minor version: deprecate first.

            ### Always

            - A bug fix comes with a test that fails without the fix.
            - Domain code depends on ports (interfaces), never on WordPress functions, Laravel or the Infrastructure layer.
            - The common case of a public API needs no configuration; options are chained methods or named arguments.

            ### Rules checked

            {$list}
            {$end}
            MD;
    }

    /**
     * Inserts or replaces the block in an existing file's content.
     */
    public static function merge(string $existing, string $block): string
    {
        $start = strpos($existing, self::START);
        $end = strpos($existing, self::END);

        if ($start !== false && $end !== false && $end > $start) {
            return substr($existing, 0, $start).$block.substr($existing, $end + strlen(self::END));
        }

        return rtrim($existing)."\n\n".$block."\n";
    }
}
