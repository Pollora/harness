<?php

declare(strict_types=1);

namespace Pollora\Harness\Support;

/**
 * A temporary checkout of the base commit, in which the tests of the base can be run beside those of the change.
 *
 * The relative mutation gate compares the score a file has on the change with the score it has on the base, and a
 * score only means something where the base's own tests run on the base's own sources. The checkout is a detached
 * git worktree outside the repository (`git worktree add --detach`), so that nothing in the working tree is touched,
 * the change's `src/` is never read by the base's tests and Pest and pcov never see the copy. Nothing needs to be
 * stashed, which would not work in CI anyway: the change is a commit, not a working-tree edit.
 *
 * Its `vendor/` is a COPY of the repository's, never a link: Composer's autoloader resolves `src/` from the real
 * path of `vendor/`, so a linked one would load the change's code and report the change's score as the base's. A
 * copy keeps the exact dependencies the change was measured with, which `composer install` would not when the
 * repository has no lock file, and needs no network. Files git ignores (a `.env`, generated fixtures) are not copied.
 */
final class BaseCheckout
{
    /**
     * @param  string  $path  The directory of the checkout
     */
    private function __construct(
        private readonly string $cwd,
        public readonly string $path,
    ) {}

    /**
     * Checks out `$base` into a fresh temporary directory and gives it the dependencies of `$cwd`.
     *
     * @param  string  $cwd  The repository of the change
     * @param  string  $base  Any ref or commit git resolves (`HEAD^1`)
     * @param  (callable(string, string): bool)|null  $dependencies  Gives the checkout its dependencies: receives the repository's directory and the checkout's, true when done. A copy of `vendor/` by default
     * @return self|null null when git cannot check the base out or the dependencies cannot be copied; the temporary directory is then removed
     */
    public static function create(string $cwd, string $base, ?callable $dependencies = null): ?self
    {
        if ($base === '' || str_starts_with($base, '-') || ! Paths::gitUsable($cwd)) {
            return null;
        }

        $path = sys_get_temp_dir().'/pollora-mutation-base-'.bin2hex(random_bytes(6));

        if (self::git($cwd, ['worktree', 'add', '--detach', '--quiet', $path, $base]) !== 0) {
            return null;
        }

        $checkout = new self($cwd, $path);
        $ready = $dependencies === null ? self::copyVendor($cwd, $path) : $dependencies($cwd, $path);

        if (! $ready) {
            $checkout->remove();

            return null;
        }

        return $checkout;
    }

    /**
     * Removes the checkout and the worktree git recorded for it. Safe to call twice.
     */
    public function remove(): void
    {
        self::git($this->cwd, ['worktree', 'remove', '--force', $this->path]);
        self::git($this->cwd, ['worktree', 'prune']);

        if (is_dir($this->path)) {
            self::run(['rm', '-rf', $this->path], $this->cwd);
        }
    }

    /**
     * Copies `vendor/` of the repository into the checkout, as a copy-on-write clone where the file system has them.
     */
    private static function copyVendor(string $cwd, string $path): bool
    {
        $vendor = rtrim($cwd, '/').'/vendor';

        if (! is_dir($vendor)) {
            return false;
        }

        $options = match (PHP_OS_FAMILY) {
            'Darwin' => ['-Rc'],
            'Linux' => ['-a', '--reflink=auto'],
            default => ['-R'],
        };

        if (self::run(['cp', ...$options, $vendor, $path.'/vendor'], $cwd) === 0) {
            return true;
        }

        // A clone option the file system or the cp does not know: start again with a plain copy.
        self::run(['rm', '-rf', $path.'/vendor'], $cwd);

        return self::run(['cp', '-R', $vendor, $path.'/vendor'], $cwd) === 0;
    }

    /**
     * @param  list<string>  $arguments
     */
    private static function git(string $cwd, array $arguments): int
    {
        return self::run(['git', ...$arguments], $cwd);
    }

    /**
     * @param  list<string>  $command
     */
    private static function run(array $command, string $cwd): int
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);

        if (! is_resource($process)) {
            return 1;
        }

        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }
}
