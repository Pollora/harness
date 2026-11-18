<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Config\NpmAuditSettings;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Npm\NpmAudit;
use Pollora\Harness\Npm\NpmAuditResult;
use Pollora\Harness\Support\Paths;

/**
 * The npm dependency audit (SEC-09) on its own, for a repository that cannot carry the whole harness:
 * the reusable workflow of the templates runs it. It is the same implementation `check` uses.
 */
final class NpmAuditCommand implements Command
{
    /** Where a template, which cannot carry a .harness.json, declares its exceptions (a directory the archives leave out). */
    private const string TEMPLATE_FILE = '.github/harness.json';

    public function name(): string
    {
        return 'npm-audit';
    }

    public function description(): string
    {
        return 'Audit the npm lock files, tolerating only the advisories npm_audit.allow lists (SEC-09)';
    }

    public function usage(): string
    {
        return <<<'TXT'
              --config=FILE       The JSON file holding the npm_audit key (default: .harness.json, then .github/harness.json)
            TXT;
    }

    public function run(Io $io): int
    {
        $directories = NpmAudit::lockDirectories($io->cwd);

        if ($directories === []) {
            $io->line('No package-lock.json to audit.');

            return self::SUCCESS;
        }

        $npm = Paths::which('npm');

        if ($npm === null) {
            $io->error('npm is not on the PATH: install Node.js (https://nodejs.org).');

            return self::FAILURE;
        }

        try {
            $settings = NpmAuditSettings::fromFile($this->configFile($io));
        } catch (InvalidConfig $invalidConfig) {
            $io->error($invalidConfig->getMessage());

            return self::FAILURE;
        }

        $result = (new NpmAudit($io->cwd, $npm, $settings, NpmAuditSettings::today()))->audit($directories);
        $io->line($result->render());
        self::writeSummary($result);

        return $result->passed() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Appends the result to the job summary of GitHub Actions, when the command runs in one.
     */
    public static function writeSummary(NpmAuditResult $result): void
    {
        $summary = getenv('GITHUB_STEP_SUMMARY');

        if (is_string($summary) && $summary !== '') {
            file_put_contents($summary, $result->markdown()."\n", FILE_APPEND);
        }
    }

    private function configFile(Io $io): string
    {
        $given = $io->option('config');

        if ($given !== null) {
            return str_starts_with($given, '/') ? $given : $io->cwd.'/'.$given;
        }

        return is_file($io->cwd.'/.harness.json') ? $io->cwd.'/.harness.json' : $io->cwd.'/'.self::TEMPLATE_FILE;
    }
}
