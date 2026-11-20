<?php

declare(strict_types=1);

namespace Pollora\Harness\Architecture;

/**
 * Reads the type declarations of a PHP source from its tokens.
 *
 * It needs no autoloader, so it judges a repository whose dependencies are not installed. It follows the
 * namespace and the `use` imports to name a parent class or a trait in full, and records for each method
 * its annotations and the significant tokens of its body. Anonymous classes, `Foo::class` and the words
 * "class" in strings or comments are not declarations.
 */
final class SourceTypes
{
    private const array NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    private const array SKIPPED = [T_WHITESPACE, T_COMMENT];

    /** @var array<string, list<TypeDeclaration>> The declarations read by `ofFile()`, by path and modification time */
    private static array $cache = [];

    /**
     * @return list<TypeDeclaration>
     */
    public static function parse(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $namespace = '';
        $imports = [];
        $types = [];
        $buffer = '';
        $modifiers = [];
        $depth = 0;
        $baseDepth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = is_array($token) ? $token[0] : $token;
            $text = is_array($token) ? $token[1] : $token;

            if ($id === T_NAMESPACE && self::nextSignificant($tokens, $i + 1, [T_NS_SEPARATOR]) !== null) {
                [$namespace, $i, $braced] = self::readNamespace($tokens, $i);
                $imports = [];
                $baseDepth = $braced ? 1 : 0;
                $depth = $baseDepth;
                $buffer = '';

                continue;
            }

            if ($id === T_USE && $depth === $baseDepth) {
                $i = self::readImport($tokens, $i, $imports);
                $buffer = '';

                continue;
            }

            if (in_array($id, [T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                $modifiers[] = $id;
                $buffer .= $text;

                continue;
            }

            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $depth === $baseDepth && ! self::isNotADeclaration($tokens, $i)) {
                $declaration = self::readType($tokens, $i, $namespace, $imports, $modifiers, $buffer);

                if ($declaration instanceof TypeDeclaration) {
                    $types[] = $declaration;
                }

                $i = self::skipBody($tokens, $i);
                $modifiers = [];
                $buffer = '';

                continue;
            }

            if ($id === '{') {
                $depth++;
            } elseif ($id === '}') {
                $depth--;
            }

            if (in_array($id, [';', '{', '}', T_OPEN_TAG], true)) {
                $buffer = '';
                $modifiers = [];
            } else {
                $buffer .= $id === T_WHITESPACE ? ' ' : $text;
            }
        }

        return $types;
    }

    /**
     * The declarations of a file, read once per process (the file is read again when its modification time changes).
     *
     * @return list<TypeDeclaration>
     */
    public static function ofFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $key = $path.'@'.(int) filemtime($path);

        return self::$cache[$key] ??= self::parse((string) file_get_contents($path));
    }

    /**
     * Whether the source declares at least one type.
     */
    public static function declaresType(string $source): bool
    {
        return StrictTypes::declaresType($source);
    }

    /**
     * @param  array<string, string>  $imports
     */
    public static function resolve(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        $segments = explode('\\', $name, 2);

        if (isset($imports[$segments[0]])) {
            return $imports[$segments[0]].(isset($segments[1]) ? '\\'.$segments[1] : '');
        }

        return ltrim($namespace.'\\'.$name, '\\');
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private static function isNotADeclaration(array $tokens, int $position): bool
    {
        for ($i = $position - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) && in_array($token[0], [T_DOUBLE_COLON, T_NEW, T_NULLSAFE_OBJECT_OPERATOR, T_OBJECT_OPERATOR], true);
        }

        return false;
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     * @param  list<int>  $also
     */
    private static function nextSignificant(array $tokens, int $from, array $also = []): ?int
    {
        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [...self::SKIPPED, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($token) && (in_array($token[0], self::NAME_TOKENS, true) || in_array($token[0], $also, true))) {
                return $i;
            }

            return null;
        }

        return null;
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     * @return array{string, int, bool} The namespace, the position of its last token, whether it is a braced block
     */
    private static function readNamespace(array $tokens, int $position): array
    {
        $name = '';
        $i = $position + 1;

        for ($count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], self::NAME_TOKENS, true)) {
                $name .= $token[1];

                continue;
            }

            if ($token === ';' || $token === '{') {
                break;
            }
        }

        return [ltrim($name, '\\'), $i, ($tokens[$i] ?? null) === '{'];
    }

    /**
     * Reads a top-level `use` statement (`use A\B;`, `use A\B as C;`, `use A\{B, C as D};`, `use function`/`const` ignored).
     *
     * @param  list<array{int, string, int}|string>  $tokens
     * @param  array<string, string>  $imports
     */
    private static function readImport(array $tokens, int $position, array &$imports): int
    {
        $i = $position + 1;
        $count = count($tokens);
        $statement = '';

        for (; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === ';') {
                break;
            }

            if (is_array($token) && ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $statement .= in_array($token[0], [T_AS, T_FUNCTION, T_CONST], true) ? ' '.$token[1].' ' : $token[1];
            } elseif (is_string($token)) {
                $statement .= $token;
            }
        }

        $statement = trim($statement);

        if (str_starts_with($statement, 'function ') || str_starts_with($statement, 'const ')) {
            return $i;
        }

        $prefix = '';
        $list = $statement;

        if (preg_match('/^(?<prefix>[^{]*)\{(?<list>.*)\}$/s', $statement, $group) === 1) {
            $prefix = $group['prefix'];
            $list = $group['list'];
        }

        foreach (explode(',', $list) as $item) {
            $parts = preg_split('/\s+as\s+/i', trim($item));
            $full = ltrim($prefix.trim((string) ($parts[0] ?? '')), '\\');

            if ($full === '') {
                continue;
            }

            $alias = isset($parts[1]) ? trim($parts[1]) : (($p = strrpos($full, '\\')) === false ? $full : substr($full, $p + 1));
            $imports[$alias] = $full;
        }

        return $i;
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     * @param  array<string, string>  $imports
     * @param  list<int>  $modifiers
     */
    private static function readType(array $tokens, int $position, string $namespace, array $imports, array $modifiers, string $annotations): ?TypeDeclaration
    {
        $kind = match ($tokens[$position][0]) {
            T_INTERFACE => 'interface',
            T_TRAIT => 'trait',
            T_ENUM => 'enum',
            default => 'class',
        };
        $count = count($tokens);
        $i = $position + 1;
        $name = null;

        for (; $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                $name = $tokens[$i][1];
                $i++;

                break;
            }
        }

        if ($name === null) {
            return null;
        }

        $extends = null;
        $reading = false;

        for (; $i < $count && $tokens[$i] !== '{'; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_EXTENDS) {
                $reading = true;

                continue;
            }

            if (is_array($token) && $token[0] === T_IMPLEMENTS) {
                $reading = false;

                continue;
            }

            if ($reading && $extends === null && is_array($token) && in_array($token[0], self::NAME_TOKENS, true) && $kind === 'class') {
                $extends = self::resolve($token[1], $namespace, $imports);
            }
        }

        $traits = [];
        $methods = [];
        $bodies = [];
        $depth = 0;
        $member = '';

        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = is_array($token) ? $token[0] : $token;

            if (in_array($id, ['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;

                continue;
            }

            if ($id === '}') {
                $depth--;

                if ($depth === 0) {
                    break;
                }

                $member = $depth === 1 ? '' : $member;

                continue;
            }

            if ($depth !== 1) {
                continue;
            }

            if ($id === T_USE) {
                [$names, $i] = self::readTraits($tokens, $i, $namespace, $imports);
                array_push($traits, ...$names);
                $member = '';

                continue;
            }

            if ($id === T_FUNCTION) {
                [$method, $body, $i] = self::readMethod($tokens, $i);

                if ($method !== null) {
                    $methods[$method] = $member;
                    $bodies[$method] = $body;
                }

                $member = '';

                continue;
            }

            if ($id === ';') {
                $member = '';
            } else {
                $member .= $id === T_WHITESPACE ? ' ' : (is_array($token) ? $token[1] : $token);
            }
        }

        return new TypeDeclaration(
            name: ltrim($namespace.'\\'.$name, '\\'),
            kind: $kind,
            final: in_array(T_FINAL, $modifiers, true),
            abstract: in_array(T_ABSTRACT, $modifiers, true),
            extends: $extends,
            traits: $traits,
            methods: $methods,
            bodies: $bodies,
            annotations: $annotations,
            namespace: $namespace,
            imports: $imports,
        );
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     * @param  array<string, string>  $imports
     * @return array{list<string>, int}
     */
    private static function readTraits(array $tokens, int $position, string $namespace, array $imports): array
    {
        $names = [];
        $i = $position + 1;

        for ($count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === ';' || $token === '{') {
                if ($token === '{') {
                    $i = self::skipBraces($tokens, $i);
                }

                break;
            }

            if (is_array($token) && in_array($token[0], self::NAME_TOKENS, true)) {
                $names[] = self::resolve($token[1], $namespace, $imports);
            }
        }

        return [$names, $i];
    }

    /**
     * Reads a method: its name and the significant tokens of its body (an abstract method has an empty body).
     *
     * @param  list<array{int, string, int}|string>  $tokens
     * @return array{string|null, list<string>, int}
     */
    private static function readMethod(array $tokens, int $position): array
    {
        $count = count($tokens);
        $i = $position + 1;
        $name = null;

        for (; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_STRING && $name === null) {
                $name = $token[1];
            }

            if ($token === '{' || $token === ';') {
                break;
            }
        }

        if (($tokens[$i] ?? null) !== '{') {
            return [$name, [], $i];
        }

        $end = self::skipBraces($tokens, $i);
        $body = [];

        for ($j = $i + 1; $j < $end; $j++) {
            $token = $tokens[$j];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $body[] = is_array($token) ? $token[1] : $token;
        }

        return [$name, $body, $end];
    }

    /**
     * The position of the brace that closes the one at `$position`.
     *
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private static function skipBraces(array $tokens, int $position): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $position; $i < $count; $i++) {
            $id = is_array($tokens[$i]) ? $tokens[$i][0] : $tokens[$i];

            if (in_array($id, ['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif ($id === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $count - 1;
    }

    /**
     * The position of the brace that closes the body of the type declared at `$position`.
     *
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private static function skipBody(array $tokens, int $position): int
    {
        $count = count($tokens);

        for ($i = $position; $i < $count; $i++) {
            if ($tokens[$i] === '{') {
                return self::skipBraces($tokens, $i);
            }
        }

        return $count - 1;
    }
}
