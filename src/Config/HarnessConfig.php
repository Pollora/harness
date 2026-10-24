<?php

declare(strict_types=1);

namespace Pollora\Harness\Config;

use JsonException;
use Pollora\Harness\Profile;

/**
 * The content of a repository's .harness.json.
 *
 * The file declares what the repository is (its profile), whether the gates
 * block or only report (its mode), and the ratchet: the best value reached so
 * far for each tracked metric.
 */
final class HarnessConfig
{
    public const FILE = '.harness.json';

    /** The files an agent may not edit and that CODEOWNERS should protect (QA-02). */
    public const DEFAULT_PROTECTED = [
        '.harness.json',
        'phpstan-baseline.neon',
        'api-surface.json',
    ];

    /**
     * @param  list<string>  $downstream  Repositories run against this one by the downstream job
     * @param  list<string>  $allowLaravel  Laravel packages a core package may still require (ARCH-04 exceptions)
     * @param  list<string>  $protected  Paths an agent may not edit (QA-02)
     */
    public function __construct(
        public readonly Profile $profile,
        public readonly Mode $mode = Mode::Observe,
        public readonly array $downstream = [],
        public readonly array $allowLaravel = [],
        public readonly Ratchet $ratchet = new Ratchet,
        public readonly array $protected = self::DEFAULT_PROTECTED,
    ) {}

    public static function exists(string $root): bool
    {
        return is_file(self::path($root));
    }

    public static function path(string $root): string
    {
        return rtrim($root, '/').'/'.self::FILE;
    }

    /**
     * @throws InvalidConfig
     */
    public static function load(string $root): self
    {
        $path = self::path($root);

        if (! is_file($path)) {
            throw InvalidConfig::missing($path);
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidConfig::unreadable($path, $exception->getMessage());
        }

        if (! is_array($data)) {
            throw InvalidConfig::unreadable($path, 'the root must be an object');
        }

        return self::fromArray($data, $path);
    }

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidConfig
     */
    public static function fromArray(array $data, string $path = self::FILE): self
    {
        $profile = Profile::tryFrom(is_string($data['profile'] ?? null) ? $data['profile'] : '');

        if ($profile === null) {
            throw InvalidConfig::unknownProfile($path, $data['profile'] ?? null);
        }

        $mode = Mode::tryFrom(is_string($data['mode'] ?? null) ? $data['mode'] : Mode::Observe->value);

        if ($mode === null) {
            throw InvalidConfig::unknownMode($path, $data['mode']);
        }

        return new self(
            profile: $profile,
            mode: $mode,
            downstream: self::stringList($data['downstream'] ?? []),
            allowLaravel: self::stringList($data['allow_laravel'] ?? []),
            ratchet: Ratchet::fromArray(is_array($data['ratchet'] ?? null) ? $data['ratchet'] : []),
            protected: array_key_exists('protected', $data) ? self::stringList($data['protected']) : self::DEFAULT_PROTECTED,
        );
    }

    public function withRatchet(Ratchet $ratchet): self
    {
        return new self($this->profile, $this->mode, $this->downstream, $this->allowLaravel, $ratchet, $this->protected);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'profile' => $this->profile->value,
            'mode' => $this->mode->value,
            'downstream' => $this->downstream,
            'allow_laravel' => $this->allowLaravel,
            'protected' => $this->protected === self::DEFAULT_PROTECTED ? null : $this->protected,
            'ratchet' => $this->ratchet->toArray(),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function save(string $root): void
    {
        file_put_contents(
            self::path($root),
            json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
