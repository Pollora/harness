<?php

declare(strict_types=1);

namespace Pollora\Harness\Surface;

use Attribute;
use FilesystemIterator;
use PhpToken;
use Pollora\Harness\Architecture\Layers;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * Reads the public surface of a repository: what a project built on Pollora
 * relies on beyond PHP signatures, which roave/backward-compatibility-check
 * already covers.
 *
 * - attributes: every attribute class, its targets and constructor parameters
 * - hooks: every hook name Pollora fires (pollora/<module>/<event>)
 * - config: every key of the publishable configuration files
 * - commands: every console command name
 *
 * Each entry is one line, "kind:name" => "description", so a change shows as
 * an entry added, removed or described differently.
 */
final readonly class SurfaceExtractor
{
    /** A hook fired by Pollora: two segments or more after "pollora/", or one written in snake_case. */
    private const string HOOK = '~^pollora/(?:[a-z0-9_-]+/[a-z0-9_/-]+|[a-z0-9]+_[a-z0-9_]+)$~';

    private const array ACTION_CALLS = ['do_action', 'do_action_ref_array', 'do_action_deprecated'];

    private const array FILTER_CALLS = ['apply_filters', 'apply_filters_ref_array', 'apply_filters_deprecated'];

    /** Configuration keys are read this deep; below, a value is an implementation detail. */
    private const int CONFIG_DEPTH = 3;

    private const array TARGETS = [
        Attribute::TARGET_CLASS => 'class',
        Attribute::TARGET_FUNCTION => 'function',
        Attribute::TARGET_METHOD => 'method',
        Attribute::TARGET_PROPERTY => 'property',
        Attribute::TARGET_CLASS_CONSTANT => 'constant',
        Attribute::TARGET_PARAMETER => 'parameter',
    ];

    public function __construct(private string $root) {}

    /**
     * @return array<string, string> Sorted by key
     */
    public function extract(): array
    {
        $layers = Layers::discover($this->root);

        $surface = [
            ...$this->attributes($layers),
            ...$this->hooks($layers),
            ...$this->config(),
            ...$this->commands($layers),
        ];

        ksort($surface);

        return $surface;
    }

    /**
     * @return array<string, string>
     */
    private function attributes(Layers $layers): array
    {
        $entries = [];

        foreach (array_keys($layers->files) as $class) {
            $reflection = $this->reflect($class);
            $declaration = $reflection?->getAttributes(Attribute::class)[0] ?? null;

            if (! $reflection instanceof ReflectionClass || ! $declaration instanceof ReflectionAttribute || $reflection->isAbstract()) {
                continue;
            }

            $flags = $declaration->getArguments()[0] ?? $declaration->getArguments()['flags'] ?? Attribute::TARGET_ALL;
            $flags = is_int($flags) ? $flags : Attribute::TARGET_ALL;
            $targets = array_values(array_filter(self::TARGETS, static fn (int $target): bool => ($flags & $target) !== 0, ARRAY_FILTER_USE_KEY));

            $entries["attribute:{$class}"] = 'targets '.implode(', ', $targets).(($flags & Attribute::IS_REPEATABLE) !== 0 ? ', repeatable' : '');

            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                $entries["attribute:{$class}(\${$parameter->getName()})"] = $this->describeParameter($parameter);
            }
        }

        return $entries;
    }

    /**
     * Hook names are read from the code, not from a running WordPress: a
     * string literal that looks like a Pollora hook, wherever it sits (a call,
     * a constant, a hook port). The call it is passed to, when there is one,
     * says whether it is an action or a filter.
     *
     * @return array<string, string>
     */
    private function hooks(Layers $layers): array
    {
        $entries = [];

        foreach ($layers->files as $path) {
            $tokens = PhpToken::tokenize((string) file_get_contents($path));
            $meaningful = array_values(array_filter($tokens, static fn (PhpToken $token): bool => ! $token->isIgnorable()));

            foreach ($meaningful as $index => $token) {
                if (! $token->is(T_CONSTANT_ENCAPSED_STRING)) {
                    continue;
                }

                $name = substr($token->text, 1, -1);

                if (preg_match(self::HOOK, $name) !== 1) {
                    continue;
                }

                $call = $index >= 2 && $meaningful[$index - 1]->text === '(' ? strtolower($meaningful[$index - 2]->text) : null;
                $kind = match (true) {
                    in_array($call, self::ACTION_CALLS, true) => 'action',
                    in_array($call, self::FILTER_CALLS, true) => 'filter',
                    default => 'hook',
                };

                // A name seen once as an action and once as a plain string stays an action.
                $entries["hook:{$name}"] = ($entries["hook:{$name}"] ?? 'hook') === 'hook' ? $kind : $entries["hook:{$name}"];
            }
        }

        return $entries;
    }

    /**
     * @return array<string, string>
     */
    private function config(): array
    {
        $entries = [];

        foreach ($this->configFiles() as $file) {
            try {
                $values = (static fn (string $path): mixed => require $path)($file);
            } catch (Throwable) {
                $values = null;
            }

            $prefix = basename($file, '.php');

            if (! is_array($values)) {
                $entries["config:{$prefix}"] = 'unreadable without the application';

                continue;
            }

            foreach ($this->flatten($values, $prefix, 1) as $key) {
                $entries["config:{$key}"] = 'key';
            }
        }

        return $entries;
    }

    /**
     * @return array<string, string>
     */
    private function commands(Layers $layers): array
    {
        $entries = [];

        foreach ($layers->files as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/#\[AsCommand\(\s*(?:name:\s*)?[\'"]([^\'"]+)[\'"]/', $source, $match) === 1
                || preg_match('/protected\s+(?:\?string\s+|string\s+)?\$(?:signature|name)\s*=\s*[\'"]([a-z][a-z0-9-]*:[^\s\'"{]+)/', $source, $match) === 1) {
                $entries["command:{$match[1]}"] = 'command';
            }
        }

        return $entries;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function flatten(array $values, string $prefix, int $depth): array
    {
        $keys = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $keys[] = "{$prefix}.{$key}";

            if (is_array($value) && ! array_is_list($value) && $depth < self::CONFIG_DEPTH) {
                array_push($keys, ...$this->flatten($value, "{$prefix}.{$key}", $depth + 1));
            }
        }

        return $keys;
    }

    /**
     * The publishable configuration: config/ at the root and the config/
     * directory of each module (src/Media/config in the framework).
     *
     * @return list<string>
     */
    private function configFiles(): array
    {
        $files = glob($this->root.'/config/*.php');
        $files = $files === false ? [] : $files;

        if (is_dir($this->root.'/src')) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root.'/src', FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php' && basename($file->getPath()) === 'config') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    private function describeParameter(ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        $description = $type instanceof \ReflectionType ? (string) $type : 'mixed';

        if ($type instanceof ReflectionNamedType && $type->allowsNull() && ! str_starts_with($description, '?') && $description !== 'mixed' && $description !== 'null') {
            $description = '?'.$description;
        }

        if ($parameter->isVariadic()) {
            return $description.', variadic';
        }

        if (! $parameter->isDefaultValueAvailable()) {
            return $description.', required';
        }

        try {
            $default = $parameter->getDefaultValue();
        } catch (Throwable) {
            return $description.', optional';
        }

        return $description.', optional = '.(is_scalar($default) || $default === null ? var_export($default, true) : get_debug_type($default));
    }

    /**
     * @return ReflectionClass<object>|null
     */
    private function reflect(string $class): ?ReflectionClass
    {
        try {
            return class_exists($class) ? new ReflectionClass($class) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
