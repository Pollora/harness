<?php

declare(strict_types=1);

/*
 * The adapter contract template (stubs/contract/): copied as the README says, in a small project that has a port, two
 * adapters that honour it and one that does not, and run by a real Pest. The template has to pass on good adapters,
 * fail on a bad one, and keep a listed difference visible without letting it hide a fix.
 */

if (! function_exists('contractProject')) {
    /**
     * Builds the project (namespace Acme\Package) and runs `pest tests/Contract` in a process of its own.
     *
     * The project is under /tmp because the namespace Pest generates from the path of a test file cannot have a
     * segment that starts with a digit (macOS temporary directories have one).
     *
     * @param  bool  $withBroken  Adds the adapter that answers an empty string for a missing key to the dataset
     * @param  string  $deviations  The PHP array of STORE_KNOWN_DEVIATIONS
     * @return array{int, string} Exit code and output
     */
    function contractProject(bool $withBroken = false, string $deviations = '[]'): array
    {
        $stubs = dirname(__DIR__, 2).'/stubs/contract';
        $root = '/tmp/pollora-harness-contract-'.bin2hex(random_bytes(5));

        foreach (['src/Contracts', 'src/Infrastructure', 'tests/Contract/Datasets'] as $directory) {
            mkdir("{$root}/{$directory}", 0o755, true);
        }

        mkdir($root.'/tmp', 0o755);

        $php = static fn (string $source): string => "<?php\n\ndeclare(strict_types=1);\n\n{$source}\n";
        file_put_contents($root.'/src/Contracts/Store.php', $php("namespace Acme\\Package\\Contracts;\n\ninterface Store\n{\n    public function put(string \$key, string \$value): void;\n\n    public function get(string \$key): ?string;\n\n    public function forget(string \$key): void;\n}"));
        file_put_contents($root.'/src/Infrastructure/ArrayStore.php', $php("namespace Acme\\Package\\Infrastructure;\n\nuse Acme\\Package\\Contracts\\Store;\n\nclass ArrayStore implements Store\n{\n    /** @var array<string, string> */\n    protected array \$values = [];\n\n    public function put(string \$key, string \$value): void\n    {\n        \$this->values[\$key] = \$value;\n    }\n\n    public function get(string \$key): ?string\n    {\n        return \$this->values[\$key] ?? null;\n    }\n\n    public function forget(string \$key): void\n    {\n        unset(\$this->values[\$key]);\n    }\n}"));
        file_put_contents($root.'/src/Infrastructure/BrokenStore.php', $php("namespace Acme\\Package\\Infrastructure;\n\nfinal class BrokenStore extends ArrayStore\n{\n    public function get(string \$key): ?string\n    {\n        return \$this->values[\$key] ?? '';\n    }\n}"));
        file_put_contents($root.'/src/Infrastructure/FileStore.php', $php("namespace Acme\\Package\\Infrastructure;\n\nuse Acme\\Package\\Contracts\\Store;\n\nfinal class FileStore implements Store\n{\n    public function __construct(private string \$directory)\n    {\n        is_dir(\$directory) || mkdir(\$directory, 0o777, true);\n    }\n\n    public function put(string \$key, string \$value): void\n    {\n        file_put_contents(\$this->path(\$key), \$value);\n    }\n\n    public function get(string \$key): ?string\n    {\n        return is_file(\$this->path(\$key)) ? (string) file_get_contents(\$this->path(\$key)) : null;\n    }\n\n    public function forget(string \$key): void\n    {\n        is_file(\$this->path(\$key)) && unlink(\$this->path(\$key));\n    }\n\n    private function path(string \$key): string\n    {\n        return \$this->directory.'/'.hash('sha256', \$key);\n    }\n}"));

        $datasets = (string) file_get_contents($stubs.'/Datasets/Stores.php.stub');
        $datasets = str_replace('const STORE_KNOWN_DEVIATIONS = [];', "const STORE_KNOWN_DEVIATIONS = {$deviations};", $datasets);

        if ($withBroken) {
            $datasets = str_replace("    'file' =>", "    'broken' => ['broken', static fn (): Store => new \\Acme\\Package\\Infrastructure\\BrokenStore],\n    'file' =>", $datasets);
        }

        file_put_contents($root.'/tests/Contract/Datasets/Stores.php', $datasets);
        copy($stubs.'/Datasets/Contract.php.stub', $root.'/tests/Contract/Datasets/Contract.php');
        copy($stubs.'/StoreContractTest.php.stub', $root.'/tests/Contract/StoreContractTest.php');

        // Pest takes as its root the directory above the vendor folder that holds its binary: a copy of the binary under
        // the project's own vendor folder makes the project the root (its tests and its Datasets folders are found).
        $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
        mkdir($root.'/vendor/pestphp/pest/bin', 0o755, true);
        copy(dirname(__DIR__, 2).'/vendor/pestphp/pest/bin/pest', $root.'/vendor/pestphp/pest/bin/pest');
        file_put_contents($root.'/vendor/autoload.php', "<?php\n\n\$loader = require '{$autoload}';\n\$loader->addPsr4('Acme\\\\Package\\\\', dirname(__DIR__).'/src');\n\nreturn \$loader;\n");
        file_put_contents($root.'/phpunit.xml', '<?xml version="1.0"?><phpunit bootstrap="vendor/autoload.php"><testsuites><testsuite name="Contract"><directory>tests/Contract</directory></testsuite></testsuites></phpunit>');

        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=2G', $root.'/vendor/pestphp/pest/bin/pest', '--colors=never'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, [...getenv(), 'COLUMNS' => '200', 'TMPDIR' => $root.'/tmp']);
        $output = (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($root);

        return [$status, $output];
    }
}

it('passes on adapters that honour the port, as the template is copied', function (): void {
    [$status, $output] = contractProject();

    expect($status)->toBe(0)
        ->and($output)->toContain('Tests:')->toContain('22 passed')
        ->and($output)->not->toContain('skipped');
});

it('fails on an adapter that does not honour the port, and names it and the case', function (): void {
    [$status, $output] = contractProject(withBroken: true);

    expect($status)->toBe(1)
        ->and($output)->toMatch('/⨯ it answers null, not an empty value, for a key it does not hold with dataset "broken"/')
        ->and($output)->not->toMatch('/⨯.*with dataset "(array|file)"/');
});

it('keeps a listed difference visible as a skip with its reason, and never as a pass', function (): void {
    [$status, $output] = contractProject(withBroken: true, deviations: "['broken|missing key is null' => 'BrokenStore::get() answers an empty string', 'broken|forget' => 'same cause']");

    expect($status)->toBe(0)
        ->and($output)->toContain('KNOWN DEVIATION: BrokenStore::get() answers an empty string [now failing with: ')->toContain('2 skipped');
});

it('fails when a listed difference no longer exists, so a fix cannot hide in the list', function (): void {
    [$status, $output] = contractProject(deviations: "['file|missing key is null' => 'was fixed']");

    expect($status)->toBe(1)
        ->and($output)->toContain('"missing key is null" for "file" now passes: delete its line');
});
