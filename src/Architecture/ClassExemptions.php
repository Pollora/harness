<?php

declare(strict_types=1);

namespace Pollora\Harness\Architecture;

use Pollora\Harness\Config\ClassPattern;
use Pollora\Harness\Config\RuleExemption;

/**
 * Applies the per-class exemptions of `allow_rules` (`{"rule": "CONV-08", "reason": "…", "classes": [...]}`)
 * to the problems of the rules that read classes from the files: CONV-02, CONV-08 and CONV-09.
 *
 * A problem line is attributed to the class it is about, so an exempted class is removed from the list and
 * named in the output; every other class keeps being judged. Nothing is dropped without a trace: each
 * exemption reports the classes it removed, and a pattern that matches no class (or none the rule
 * reports) is a stale exemption, a warning today.
 *
 * ARCH-08 is not class-exemptible: it loads the classes through reflection to judge them, which a
 * repository whose dependencies are not installed (the ratchet, `promote-report`) cannot do.
 */
final class ClassExemptions
{
    /**
     * The problems the rule reports, read the way its Pest test reads them (the same code).
     *
     * @return list<string>
     */
    public static function problemsOf(string $rule, Layers $layers, string $root): array
    {
        return match ($rule) {
            'CONV-02' => RawRegistrationArrays::problems($layers, $root),
            'CONV-08' => FinalClasses::problems($layers),
            'CONV-09' => FacadeClasses::problems($layers),
            default => [],
        };
    }

    /**
     * Removes from `$problems` the ones about an exempted class.
     *
     * A problem matched by two exemptions is attributed to the first. The exemptions must all be about `$rule`.
     *
     * @param  list<RuleExemption>  $exemptions  The per-class entries of the rule, in the order of `allow_rules`
     * @param  list<string>  $problems  What the rule reports
     */
    public static function apply(array $exemptions, array $problems, Layers $layers, string $root): Exempted
    {
        $subjects = [];
        $byPath = self::classesByPath($layers, $root);

        foreach ($problems as $problem) {
            $subjects[$problem] = self::subject($problem, $byPath);
        }

        $known = array_values(array_unique([...array_keys($layers->files), ...array_filter($subjects, static fn (?string $subject): bool => $subject !== null)]));
        $removed = [];
        $effects = [];

        foreach ($exemptions as $exemption) {
            $classes = [];
            $count = 0;
            $stale = [];
            $unused = [];

            foreach ($exemption->classes as $pattern) {
                $matchesClass = array_filter($known, static fn (string $class): bool => ClassPattern::matches($pattern, $class)) !== [];
                $reported = false;

                foreach ($subjects as $problem => $subject) {
                    if ($subject === null || ! ClassPattern::matches($pattern, $subject)) {
                        continue;
                    }

                    $reported = true;

                    if (! isset($removed[$problem])) {
                        $removed[$problem] = true;
                        $classes[$subject] = $subject;
                        $count++;
                    }
                }

                if (! $matchesClass) {
                    $stale[] = $pattern;
                } elseif (! $reported) {
                    $unused[] = $pattern;
                }
            }

            ksort($classes);
            $effects[] = new ExemptionEffect($exemption, array_values($classes), $count, $stale, $unused);
        }

        return new Exempted(
            $problems,
            array_values(array_filter($problems, static fn (string $problem): bool => ! isset($removed[$problem]))),
            $effects,
        );
    }

    /**
     * The class a problem line is about, or null when it names none.
     *
     * The lines of CONV-08 and CONV-09 start with the class (`Acme\Foo is not final…`, `Acme\Foo::bar() is logic…`);
     * those of CONV-02 start with `path:line` and a short class name, so the class is the one that the file declares.
     *
     * @param  array<string, string>  $byPath  Class by the path the problems print (relative to the root)
     */
    public static function subject(string $problem, array $byPath): ?string
    {
        if (preg_match('/^(\S+\.php)(?::\d+)? /', $problem, $file) === 1) {
            return $byPath[$file[1]] ?? null;
        }

        return preg_match('/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?:::| )/', $problem, $class) === 1 ? ltrim($class[1], '\\') : null;
    }

    /**
     * @return array<string, string>
     */
    public static function classesByPath(Layers $layers, string $root): array
    {
        $byPath = [];

        foreach ($layers->files as $class => $path) {
            $byPath[$root !== '' && str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : $path] = $class;
        }

        return $byPath;
    }
}
