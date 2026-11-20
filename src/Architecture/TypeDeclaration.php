<?php

declare(strict_types=1);

namespace Pollora\Harness\Architecture;

/**
 * What the source of one class, interface, trait or enum says about itself, read from its tokens (no autoload).
 *
 * The architecture checks that run on files (CONV-08, CONV-09, CONV-12) need it to judge a repository
 * whose dependencies are not installed, and a class whose parent cannot be loaded.
 */
final readonly class TypeDeclaration
{
    /**
     * @param  'class'|'interface'|'trait'|'enum'  $kind
     * @param  string  $name  Fully qualified, without leading backslash
     * @param  string|null  $extends  Fully qualified parent class, null when it has none (an interface may extend several: not recorded)
     * @param  list<string>  $traits  Fully qualified traits the class uses
     * @param  array<string, string>  $methods  Method name => its annotations (attributes and doc comment, as text)
     * @param  array<string, list<string>>  $bodies  Method name => the significant tokens of its body, as text
     * @param  string  $annotations  Attributes and doc comment of the type itself, as text
     * @param  string  $namespace  The namespace the type is declared in
     * @param  array<string, string>  $imports  Alias => fully qualified name of the file's `use` imports
     */
    public function __construct(
        public string $name,
        public string $kind,
        public bool $final,
        public bool $abstract,
        public ?string $extends,
        public array $traits,
        public array $methods,
        public array $bodies,
        public string $annotations,
        public string $namespace = '',
        public array $imports = [],
    ) {}

    /** Names a class written in this type's source in full, as the language resolves it (imports, then the namespace). */
    public function resolve(string $written): string
    {
        return SourceTypes::resolve($written, $this->namespace, $this->imports);
    }

    /** The last segment of the name. */
    public function shortName(): string
    {
        $position = strrpos($this->name, '\\');

        return $position === false ? $this->name : substr($this->name, $position + 1);
    }

    /** Whether the type uses a trait whose short name is `$short` (Macroable is Illuminate's or Spatie's). */
    public function usesTraitNamed(string $short): bool
    {
        foreach ($this->traits as $trait) {
            if ($trait === $short || str_ends_with($trait, '\\'.$short)) {
                return true;
            }
        }

        return false;
    }
}
