<?php

declare(strict_types=1);

namespace Pollora\Harness\Performance;

use JsonException;

/**
 * The query budgets of a repository (PERF-01): how many queries each type of page may run.
 *
 * The file is the one the framework keeps in `tests/e2e/budgets.json`:
 *
 * ```json
 * { "pages": { "home": { "wordpress": 24, "laravel": 0 }, "rest": { "wordpress": 19, "laravel": null } } }
 * ```
 *
 * A page holds one budget per engine: a whole number (0 is a real limit) or `null`, which means "measured, no
 * budget yet". This class only reads the file; it never runs a site. The ratchet is `raisedSince()`: a budget that
 * went up, or that was dropped, since the base is a loosening, and a loosening needs a written decision.
 */
final readonly class Budgets
{
    /**
     * @param  array<string, array<string, int|null>>  $pages  page => engine => budget
     */
    private function __construct(public array $pages) {}

    /**
     * Reads the content of a budgets file.
     *
     * @return self|list<string> The budgets, or the problems that make the file unusable (never an empty list)
     */
    public static function parse(string $json): self|array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            return ['not valid JSON: '.$jsonException->getMessage()];
        }

        $pages = is_array($data) ? ($data['pages'] ?? null) : null;

        if (! is_array($pages) || $pages === [] || array_is_list($pages)) {
            return ['"pages" must be a non-empty object of page types'];
        }

        $problems = [];
        $budgets = [];

        foreach ($pages as $page => $engines) {
            $page = (string) $page;

            if (! is_array($engines) || $engines === [] || array_is_list($engines)) {
                $problems[] = sprintf('page "%s" must be a non-empty object of engines (e.g. "wordpress", "laravel")', $page);

                continue;
            }

            foreach ($engines as $engine => $budget) {
                if ($budget !== null && (! is_int($budget) || $budget < 0)) {
                    $problems[] = sprintf('%s.%s is %s: a budget is a whole number of queries (0 or more) or null', $page, $engine, is_scalar($budget) ? var_export($budget, true) : get_debug_type($budget));

                    continue;
                }

                $budgets[$page][(string) $engine] = $budget;
            }
        }

        return $problems === [] ? new self($budgets) : $problems;
    }

    /**
     * The budgets that are `null`: measured but not held to a limit, as `page.engine`.
     *
     * @return list<string>
     */
    public function unset(): array
    {
        $unset = [];

        foreach ($this->pages as $page => $engines) {
            foreach ($engines as $engine => $budget) {
                if ($budget === null) {
                    $unset[] = $page.'.'.$engine;
                }
            }
        }

        return $unset;
    }

    /**
     * How many budgets hold a limit.
     */
    public function set(): int
    {
        $count = 0;

        foreach ($this->pages as $engines) {
            $count += count(array_filter($engines, static fn (?int $budget): bool => $budget !== null));
        }

        return $count;
    }

    /**
     * The budgets of `$base` that this file loosens: raised, turned into `null`, or removed with their page.
     * A budget that was `null` in the base cannot be loosened; a budget that is new is a tightening.
     *
     * @return list<string> One line per loosened budget
     */
    public function loosenedSince(self $base): array
    {
        $lines = [];

        foreach ($base->pages as $page => $engines) {
            foreach ($engines as $engine => $before) {
                if ($before === null) {
                    continue;
                }

                $key = $page.'.'.$engine;

                if (! array_key_exists($page, $this->pages) || ! array_key_exists($engine, $this->pages[$page])) {
                    $lines[] = sprintf('%s: removed (was %d)', $key, $before);

                    continue;
                }

                $now = $this->pages[$page][$engine];

                if ($now === null) {
                    $lines[] = sprintf('%s: set to null, no budget any more (was %d)', $key, $before);
                } elseif ($now > $before) {
                    $lines[] = sprintf('%s: raised from %d to %d', $key, $before, $now);
                }
            }
        }

        return $lines;
    }
}
