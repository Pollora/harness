<?php

declare(strict_types=1);

namespace Pollora\Harness\Performance;

use Pollora\Harness\Config\PerformanceSettings;

/**
 * The static check of the nightly measures (PERF-07): boot, cold discovery and thumbnail timings that a scheduled
 * workflow measures and compares with the last good night.
 *
 * Nothing runs. The check reads the repository and proves the wiring is declared: the file that measures and the
 * file that compares exist, a workflow has a `schedule` trigger, that workflow runs both, and the tolerance it
 * gives the comparison is no looser than 10 %. It cannot prove that the nightly job passes; that is the nightly
 * job's own result.
 *
 * The reference a night is compared with is not a file of the repository: the framework keeps it in a CI cache
 * (`actions/cache`), so the comparison script, which holds the policy, stands for it here.
 */
final class NightlyMeasures
{
    /** The loosest tolerance PERF-07 accepts: an alert above 10 %. */
    public const float MAX_TOLERANCE = 0.1;

    /**
     * @return array{problems: list<string>, facts: list<string>}
     */
    public static function check(string $cwd, PerformanceSettings $settings): array
    {
        $problems = [];
        $facts = [];
        $measure = $settings->measurePath();
        $compare = $settings->comparePath();

        foreach ([['measure', $measure], ['compare', $compare]] as [$role, $file]) {
            if (! is_file($cwd.'/'.$file)) {
                $problems[] = sprintf('the nightly %s file %s does not exist', $role, $file);
            }
        }

        $workflows = self::workflows($cwd, $settings->workflow);

        if ($workflows === []) {
            $problems[] = $settings->workflow === null ? 'no workflow in .github/workflows' : sprintf('the workflow %s does not exist', $settings->workflow);

            return ['problems' => $problems, 'facts' => $facts];
        }

        $measureStem = self::stem($measure);
        $compareStem = self::stem($compare);
        $scheduled = array_filter($workflows, self::hasSchedule(...));

        if ($scheduled === []) {
            $problems[] = 'no workflow has a "schedule" trigger (on: schedule: - cron), so nothing measures nightly';

            return ['problems' => $problems, 'facts' => $facts];
        }

        $runner = null;

        foreach ($scheduled as $name => $text) {
            if (self::runs($text, $measureStem) !== []) {
                $runner = $name;

                break;
            }
        }

        if ($runner === null) {
            $problems[] = sprintf('no scheduled workflow (%s) runs %s', implode(', ', array_keys($scheduled)), $measureStem);

            return ['problems' => $problems, 'facts' => $facts];
        }

        $facts[] = sprintf('%s is scheduled and runs %s', $runner, $measureStem);
        $comparisons = self::runs($scheduled[$runner], $compareStem);

        if ($comparisons === []) {
            $problems[] = sprintf('%s measures but never runs %s: a slowdown would raise no alert', $runner, $compareStem);

            return ['problems' => $problems, 'facts' => $facts];
        }

        $tolerances = [];

        foreach ($comparisons as $line) {
            if (preg_match('/--tolerance[= ]+([0-9]*\.?[0-9]+)/', $line, $found) === 1) {
                $tolerances[] = (float) $found[1];
            }
        }

        if ($tolerances === []) {
            $facts[] = sprintf('%s runs %s without --tolerance: the script\'s own default applies (not verified, the limit is %s)', $runner, $compareStem, self::percent(self::MAX_TOLERANCE));
        } elseif (max($tolerances) > self::MAX_TOLERANCE) {
            $problems[] = sprintf('%s compares with a tolerance of %s, above the %s PERF-07 allows', $runner, self::percent(max($tolerances)), self::percent(self::MAX_TOLERANCE));
        } else {
            $facts[] = sprintf('the comparison alerts above %s', self::percent(max($tolerances)));
        }

        return ['problems' => $problems, 'facts' => $facts];
    }

    /**
     * Whether a workflow's text declares a `schedule` trigger with a cron.
     *
     * Read line by line (no YAML parser is required): the `on:` key at the root, then a `schedule:` key and a
     * `cron:` value inside it. An inline `on: [push, schedule]` has no cron, so it does not count.
     */
    public static function hasSchedule(string $text): bool
    {
        $inOn = false;
        $schedule = null;

        foreach (self::lines($text) as $line) {
            if (preg_match('/^["\']?on["\']?\s*:/', $line) === 1) {
                $inOn = true;
                $schedule = null;

                continue;
            }

            if (preg_match('/^\S/', $line) === 1) {
                $inOn = false;
                $schedule = null;

                continue;
            }

            if (! $inOn) {
                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line));

            if (preg_match('/^\s+schedule\s*:/', $line) === 1) {
                $schedule = $indent;

                continue;
            }

            // a sibling of `schedule:` (same indentation, not a list item) closes it
            if ($schedule !== null && $indent <= $schedule && ! str_starts_with(ltrim($line), '-') && trim($line) !== '') {
                $schedule = null;
            }

            if ($schedule !== null && preg_match('/\bcron\s*:\s*\S/', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The lines of a workflow that run something named `$stem` (not an artifact name, a path or a cache key).
     *
     * @return list<string>
     */
    public static function runs(string $text, string $stem): array
    {
        $pattern = '/(?<![\w-])'.preg_quote($stem, '/').'(?![\w-])/';

        return array_values(array_filter(
            self::lines($text),
            static fn (string $line): bool => preg_match($pattern, $line) === 1
                && preg_match('/^\s*-?\s*(name|path|key|restore-keys|if|E2E_\w+)\s*:/', $line) !== 1,
        ));
    }

    /**
     * The workflows to read, name => text, without their comments.
     *
     * @return array<string, string>
     */
    private static function workflows(string $cwd, ?string $only): array
    {
        $files = $only === null ? [...self::glob($cwd.'/.github/workflows/*.yml'), ...self::glob($cwd.'/.github/workflows/*.yaml')] : [$cwd.'/'.$only];
        $workflows = [];

        foreach ($files as $file) {
            if (is_file($file)) {
                $workflows[ltrim(substr($file, strlen($cwd)), '/')] = (string) file_get_contents($file);
            }
        }

        ksort($workflows);

        return $workflows;
    }

    /**
     * @return list<string> The lines of a YAML text with the comments cut off
     */
    private static function lines(string $text): array
    {
        $lines = preg_split('/\R/', $text);

        return array_map(
            static fn (string $line): string => rtrim((string) preg_replace('/(^|\s)#.*$/', '', $line)),
            $lines === false ? [] : $lines,
        );
    }

    /**
     * @return list<string>
     */
    private static function glob(string $pattern): array
    {
        $files = glob($pattern);

        return $files === false ? [] : $files;
    }

    /**
     * `tests/e2e/specs/perf-metrics.spec.ts` => `perf-metrics`: what a command line calls the file.
     */
    private static function stem(string $file): string
    {
        return explode('.', basename($file))[0];
    }

    private static function percent(float $share): string
    {
        return rtrim(rtrim(number_format($share * 100, 2, '.', ''), '0'), '.').' %';
    }
}
