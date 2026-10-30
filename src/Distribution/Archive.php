<?php

declare(strict_types=1);

namespace Pollora\Harness\Distribution;

use FilesystemIterator;
use PharData;
use PharFileInfo;
use Pollora\Harness\Profile;
use RecursiveIteratorIterator;
use Throwable;

/**
 * What the archive of a tagged release carries (QA-06).
 *
 * Composer downloads the archive GitHub builds with `git archive`. Without an
 * `export-ignore` line, the tests, the CI workflows, the tool configuration and
 * the agent files travel with every install. The check reads the archive itself:
 * an `export-ignore` on a directory cannot be seen file by file.
 */
final readonly class Archive
{
    /** Top-level directories that serve development only. */
    private const array DEV_DIRECTORIES = ['tests', 'test', '.github', '.claude', '.husky', '.vscode'];

    /** Top-level files that serve development only. */
    private const string DEV_FILES = '~^(\.harness\.json|AGENTS\.md|CLAUDE\.md|api-surface\.json|rector\.php|composer-dependency-analyser\.php|pint\.json|renovate\.json|codecov\.ya?ml|\.gitignore|\.gitattributes|\.editorconfig|\.styleci\.yml|infection\.json5?(\.dist)?|phpstan[^/]*\.neon(\.dist)?|phpunit[^/]*\.xml(\.dist)?|phpcs[^/]*\.xml(\.dist)?)$~';

    private const string HEADER = '# Pollora harness (QA-06): development files stay out of the archive Composer downloads';

    public function __construct(private string $root) {}

    /**
     * A package is installed from its archive; a template or a project is the product itself.
     */
    public static function applies(Profile $profile): bool
    {
        return in_array($profile, [Profile::Core, Profile::Framework, Profile::Integration, Profile::WpPlugin], true);
    }

    /**
     * @return list<string>|null The files `git archive` would hold, null when it cannot be built
     */
    public function entries(): ?array
    {
        $tar = tempnam(sys_get_temp_dir(), 'pollora-archive-');

        if ($tar === false) {
            return null;
        }

        // Phar picks the format from the extension.
        $file = $tar.'.tar';
        rename($tar, $file);

        try {
            $process = proc_open(
                ['git', 'archive', '--format=tar', '--worktree-attributes', '-o', $file, 'HEAD'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $this->root,
            );

            if (! is_resource($process)) {
                return null;
            }

            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            if (proc_close($process) !== 0 || filesize($file) === 0) {
                return null;
            }

            $prefix = 'phar://'.$file.'/';
            $entries = [];

            foreach (new RecursiveIteratorIterator(new PharData($file, FilesystemIterator::SKIP_DOTS)) as $entry) {
                if ($entry instanceof PharFileInfo && $entry->isFile()) {
                    $entries[] = substr($entry->getPathname(), strlen($prefix));
                }
            }

            sort($entries);

            return $entries;
        } catch (Throwable) {
            return null;
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param  list<string>  $entries
     * @return list<string> The entries that serve development only
     */
    public static function offenders(array $entries): array
    {
        return array_values(array_filter($entries, self::isDevelopment(...)));
    }

    /**
     * The `.gitattributes` lines that keep these entries out of the archive.
     *
     * @param  list<string>  $offenders
     * @return list<string>
     */
    public static function linesFor(array $offenders): array
    {
        $lines = [];

        foreach ($offenders as $offender) {
            $lines['/'.explode('/', $offender)[0].' export-ignore'] = true;
        }

        // Once the file is tracked it would travel itself, and the next check would fail.
        if ($lines !== []) {
            $lines['/.gitattributes export-ignore'] = true;
        }

        $lines = array_keys($lines);
        sort($lines);

        return $lines;
    }

    /**
     * The lines for every development file or directory present at the top level of the
     * repository, tracked or not yet: what `init` can say before the first commit.
     *
     * @return list<string>
     */
    public function standardLines(): array
    {
        $names = [];

        $listing = scandir($this->root);

        foreach (is_array($listing) ? $listing : [] as $name) {
            if (in_array($name, self::DEV_DIRECTORIES, true) && is_dir($this->root.'/'.$name)) {
                $names[] = $name;
            } elseif (is_file($this->root.'/'.$name) && preg_match(self::DEV_FILES, $name) === 1) {
                $names[] = $name;
            }
        }

        return self::linesFor($names);
    }

    /**
     * The lines that .gitattributes does not hold yet.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public function missing(array $lines): array
    {
        $path = $this->root.'/.gitattributes';
        $present = [];

        $existing = preg_split('/\R/', is_file($path) ? (string) file_get_contents($path) : '');

        foreach (is_array($existing) ? $existing : [] as $line) {
            $line = trim($line);

            if ($line !== '' && ! str_starts_with($line, '#') && preg_match('~^(\S+)\s+.*\bexport-ignore\b~', $line, $matches) === 1) {
                $present[$matches[1]] = true;
            }
        }

        return array_values(array_filter($lines, static fn (string $line): bool => ! isset($present[explode(' ', $line)[0]])));
    }

    /**
     * Adds the missing lines to .gitattributes, creating it when needed.
     *
     * @param  list<string>  $lines
     * @return list<string> The lines that were added
     */
    public function ensure(array $lines): array
    {
        $added = $this->missing($lines);

        if ($added === []) {
            return [];
        }

        $path = $this->root.'/.gitattributes';
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        $block = (str_contains($content, self::HEADER) ? '' : self::HEADER."\n").implode("\n", $added)."\n";
        file_put_contents($path, ($content === '' ? '' : rtrim($content)."\n").$block);

        return $added;
    }

    private static function isDevelopment(string $entry): bool
    {
        $top = explode('/', $entry)[0];

        return $top === $entry
            ? preg_match(self::DEV_FILES, $entry) === 1
            : in_array($top, self::DEV_DIRECTORIES, true);
    }
}
