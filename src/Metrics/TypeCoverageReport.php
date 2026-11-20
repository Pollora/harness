<?php

declare(strict_types=1);

namespace Pollora\Harness\Metrics;

/**
 * What {@see TypeCoverage} found: typed and total counts per kind of
 * declaration, the files that concentrate the missing types, the files that
 * could not be read.
 */
final readonly class TypeCoverageReport
{
    /**
     * @param  array{int, int}  $returns  Typed and total return types
     * @param  array{int, int}  $parameters  Typed and total parameters
     * @param  array{int, int}  $properties  Typed and total properties
     * @param  array<string, int>  $worstFiles  Untyped declarations per file, the five worst, worst first
     * @param  list<string>  $unparsed  Files that did not parse (not counted)
     * @param  int  $excluded  Parameters left out because a WordPress parent forbids typing them
     * @param  int  $files  Files read
     */
    public function __construct(
        public array $returns,
        public array $parameters,
        public array $properties,
        public array $worstFiles,
        public array $unparsed,
        public int $excluded,
        public int $files,
    ) {}

    public function typed(): int
    {
        return $this->returns[0] + $this->parameters[0] + $this->properties[0];
    }

    public function total(): int
    {
        return $this->returns[1] + $this->parameters[1] + $this->properties[1];
    }

    /**
     * The percentage of typed declarations, two decimals; null when the
     * source has no declaration to type (so the metric is "not measured").
     */
    public function percentage(): ?float
    {
        return $this->total() === 0 ? null : round($this->typed() / $this->total() * 100, 2);
    }

    /**
     * The lines `check` prints under the ratchet: the totals by kind, the
     * files that concentrate the gap and the files that did not parse.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        if ($this->percentage() === null && $this->unparsed === []) {
            return [];
        }

        $lines = [];

        if ($this->percentage() !== null) {
            $lines[] = sprintf(
                '  Type coverage: %s %% (%d of %d declarations: returns %s, parameters %s, properties %s)%s.',
                number_format($this->percentage(), 2, '.', ''),
                $this->typed(),
                $this->total(),
                self::share($this->returns),
                self::share($this->parameters),
                self::share($this->properties),
                $this->excluded === 0 ? '' : sprintf('; %d parameter(s) of WordPress overrides left out', $this->excluded),
            );
        }

        foreach ($this->worstFiles as $path => $count) {
            $lines[] = sprintf('    %d untyped in %s', $count, $path);
        }

        foreach ($this->unparsed as $path) {
            $lines[] = sprintf('  Type coverage: %s does not parse and was not counted.', $path);
        }

        return $lines;
    }

    /**
     * @param  array{int, int}  $pair
     */
    private static function share(array $pair): string
    {
        return $pair[1] === 0 ? 'n/a' : number_format($pair[0] / $pair[1] * 100, 2, '.', '').' %';
    }
}
