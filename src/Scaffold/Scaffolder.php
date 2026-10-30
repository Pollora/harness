<?php

declare(strict_types=1);

namespace Pollora\Harness\Scaffold;

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Distribution\Archive;
use Pollora\Harness\Profile;
use Pollora\Harness\Support\Paths;

/**
 * Applies the harness to a repository, without overwriting what it has.
 *
 * Every file is created only when missing; the few files that are patched
 * (phpstan.neon, composer.json, .gitignore, AGENTS.md, CLAUDE.md) only gain
 * the lines the harness needs. Each change is returned as a line for the
 * report, so the author reviews exactly what moved.
 */
final class Scaffolder
{
    private const string PHPSTAN_INCLUDE = 'vendor/pollora/harness/presets/phpstan/base.neon';

    private const string LARAVEL_INCLUDE = 'vendor/pollora/harness/presets/phpstan/laravel.neon';

    /** @var list<string> */
    private array $changes = [];

    public function __construct(
        private readonly string $root,
        private readonly HarnessConfig $config,
    ) {}

    /**
     * @return list<string> What was created or changed
     */
    public function apply(int|string $currentLevel): array
    {
        $this->changes = [];

        $this->config->save($this->root);
        $this->changes[] = 'wrote .harness.json';

        $this->phpstan($currentLevel);
        $this->fromStub('tests/Arch.php', 'Arch.php.stub');
        $this->rector();
        $this->workflow();
        $this->fromStub('.claude/settings.json', 'claude-settings.json.stub');
        $this->agents();
        $this->claude();
        $this->composerScripts();
        $this->gitignore();
        $this->exportIgnore();

        return $this->changes;
    }

    private function phpstan(int|string $level): void
    {
        $includes = [self::PHPSTAN_INCLUDE];

        if (in_array($this->config->profile, [Profile::Framework, Profile::Integration], true) && $this->requiresLarastan()) {
            $includes[] = self::LARAVEL_INCLUDE;
        }

        foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'] as $file) {
            $path = $this->root.'/'.$file;

            if (! is_file($path)) {
                continue;
            }

            $neon = (string) file_get_contents($path);
            $missing = array_values(array_filter($includes, static fn (string $include): bool => ! str_contains($neon, $include)));

            if ($missing === []) {
                return;
            }

            // The base preset already includes the WordPress stubs, and the Laravel preset Larastan: twice is an error
            $neon = (string) preg_replace('#^[ \t]*-[ \t]*vendor/(szepeviktor/phpstan-wordpress|larastan/larastan)/extension\.neon[ \t]*\r?\n#m', '', $neon);

            $lines = implode('', array_map(static fn (string $include): string => "    - {$include}\n", $missing));
            $neon = preg_match('/^includes:\s*$/m', $neon) === 1
                ? (string) preg_replace('/^includes:\s*$/m', "includes:\n".rtrim($lines, "\n"), $neon, 1)
                : "includes:\n{$lines}\n".$neon;

            file_put_contents($path, $neon);
            $this->changes[] = "added the harness preset to the includes of {$file}";

            return;
        }

        if (is_file($this->root.'/phpstan-baseline.neon')) {
            $includes[] = 'phpstan-baseline.neon';
        }

        $paths = array_filter(['src', 'app'], fn (string $path): bool => is_dir($this->root.'/'.$path));

        $this->write('phpstan.neon', strtr((string) file_get_contents(Paths::stub('phpstan.neon.stub')), [
            '{{includes}}' => implode("\n", array_map(static fn (string $include): string => "    - {$include}", $includes)),
            '{{level}}' => (string) $level,
            '{{paths}}' => implode("\n", array_map(static fn (string $path): string => "        - {$path}", $paths === [] ? ['src'] : $paths)),
        ]));
    }

    /**
     * Larastan boots the application: the Laravel preset is only usable where the repository requires it.
     */
    private function requiresLarastan(): bool
    {
        $path = $this->root.'/composer.json';

        if (! is_file($path)) {
            return false;
        }

        $composer = json_decode((string) file_get_contents($path), true);

        if (! is_array($composer)) {
            return false;
        }

        foreach (['require', 'require-dev'] as $section) {
            $packages = $composer[$section] ?? null;

            if (is_array($packages) && array_key_exists('larastan/larastan', $packages)) {
                return true;
            }
        }

        return false;
    }

    private function rector(): void
    {
        $this->fromStub('rector.php', 'rector.php.stub', [
            '{{profileCase}}' => $this->config->profile->name,
        ]);
    }

    private function workflow(): void
    {
        $branches = $this->config->profile === Profile::Framework ? 'main, develop' : 'main';

        $this->fromStub('.github/workflows/harness.yml', 'workflow.yml.stub', [
            '{{branches}}' => $branches,
        ]);
    }

    private function agents(): void
    {
        $path = $this->root.'/AGENTS.md';
        $block = AgentsFile::block($this->config);

        if (! is_file($path)) {
            $this->write('AGENTS.md', "# Agent instructions\n\n".$block."\n");

            return;
        }

        $merged = AgentsFile::merge((string) file_get_contents($path), $block);

        if ($merged !== file_get_contents($path)) {
            file_put_contents($path, $merged);
            $this->changes[] = 'updated the harness block of AGENTS.md';
        }
    }

    /**
     * Claude Code reads CLAUDE.md, not AGENTS.md: an import line makes it read both.
     */
    private function claude(): void
    {
        $path = $this->root.'/CLAUDE.md';

        if (is_file($path) && ! str_contains((string) file_get_contents($path), '@AGENTS.md')) {
            file_put_contents($path, rtrim((string) file_get_contents($path))."\n\nThe Pollora harness rules for this repository: @AGENTS.md\n");
            $this->changes[] = 'imported AGENTS.md from CLAUDE.md';
        }
    }

    private function composerScripts(): void
    {
        $path = $this->root.'/composer.json';
        $composer = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($composer)) {
            return;
        }

        $scripts = is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];
        $wanted = [
            'qa' => '@php vendor/bin/pollora-qa check',
            'qa:fast' => '@php vendor/bin/pollora-qa check --fast',
        ];

        $added = array_diff_key($wanted, $scripts);

        if ($added === []) {
            return;
        }

        $composer['scripts'] = $scripts + $added;
        file_put_contents($path, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        $this->changes[] = 'added the composer scripts '.implode(' and ', array_keys($added));
    }

    private function gitignore(): void
    {
        $path = $this->root.'/.gitignore';
        $content = is_file($path) ? (string) file_get_contents($path) : '';

        if (preg_match('#^/?\.harness/?$#m', $content) === 1) {
            return;
        }

        file_put_contents($path, rtrim($content)."\n# Pollora harness reports\n/.harness/\n");
        $this->changes[] = 'ignored .harness/ (reports) in .gitignore';
    }

    /**
     * QA-06: the files this method and the author's own tooling add must not travel in the archive.
     * The files init creates are named even when they do not exist yet (api-surface.json, written by `surface --update`).
     */
    private function exportIgnore(): void
    {
        if (! Archive::applies($this->config->profile)) {
            return;
        }

        $archive = new Archive($this->root);
        $harness = ['/.gitattributes export-ignore', '/.claude export-ignore', '/.harness.json export-ignore', '/AGENTS.md export-ignore', '/api-surface.json export-ignore', '/composer-dependency-analyser.php export-ignore', '/rector.php export-ignore'];
        $added = $archive->ensure([...$harness, ...$archive->standardLines()]);

        if ($added !== []) {
            $this->changes[] = 'kept the development files out of the archive (.gitattributes: '.count($added).' lines)';
        }
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function fromStub(string $target, string $stub, array $replacements = []): void
    {
        if (file_exists($this->root.'/'.$target)) {
            $this->changes[] = "kept the existing {$target}";

            return;
        }

        $this->write($target, strtr((string) file_get_contents(Paths::stub($stub)), $replacements));
    }

    private function write(string $target, string $content): void
    {
        $path = $this->root.'/'.$target;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }

        file_put_contents($path, $content);
        $this->changes[] = "created {$target}";
    }
}
