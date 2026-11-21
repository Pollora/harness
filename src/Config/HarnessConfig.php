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
final readonly class HarnessConfig
{
    public const string FILE = '.harness.json';

    /** The services a repository's tests may ask the CI to start (the `services` key). */
    public const array SERVICES = ['redis'];

    /** The files an agent may not edit and that CODEOWNERS should protect (QA-02). */
    public const array DEFAULT_PROTECTED = [
        '.harness.json',
        'phpstan-baseline.neon',
        'api-surface.json',
    ];

    /** The top-level keys this version reads: any other key of the file is kept as it is when the file is saved. */
    private const array KNOWN_KEYS = [
        'profile', 'mode', 'downstream', 'allow_laravel', 'protected', 'observed', 'allow_commands',
        'allow_rules', 'ratchet', 'mutation', 'npm_audit', 'services', 'slow_step_share', 'performance',
    ];

    /**
     * @param  list<string>  $downstream  Repositories run against this one by the downstream job
     * @param  list<string>  $allowLaravel  Laravel packages a core package may still require (ARCH-04 exceptions)
     * @param  list<string>  $protected  Paths an agent may not edit (QA-02)
     * @param  list<string>  $observed  Gates (by name, e.g. "Rector") that are reported but never block, in enforce mode too
     * @param  list<CommandExemption>  $allowCommands  Commands that keep a name without the pollora: prefix, each with its reason (CONV-06)
     * @param  list<RuleExemption>  $allowRules  Architecture rules of tests/Arch.php that are skipped, each with its reason
     * @param  MutationSettings  $mutation  What `check --mutation` mutates and the score it must reach (the `mutation` key)
     * @param  NpmAuditSettings  $npmAudit  The npm advisories tolerated for a limited time (the `npm_audit` key, SEC-09)
     * @param  list<string>  $services  Services the CI starts for the tests and the quality gates, among `SERVICES` (the `services` key)
     * @param  float|null  $slowStepShare  A step that takes more than this share (0 to 1, exclusive of 0) of the run's total time is flagged as slow in the report; null disables the warning (the `slow_step_share` key)
     * @param  PerformanceSettings  $performance  Where the query budgets and the nightly measures are (the `performance` key, PERF-01 and PERF-07)
     */
    public function __construct(
        public Profile $profile,
        public Mode $mode = Mode::Observe,
        public array $downstream = [],
        public array $allowLaravel = [],
        public Ratchet $ratchet = new Ratchet,
        public array $protected = self::DEFAULT_PROTECTED,
        public array $observed = [],
        public array $allowCommands = [],
        public array $allowRules = [],
        public MutationSettings $mutation = new MutationSettings,
        public NpmAuditSettings $npmAudit = new NpmAuditSettings,
        public array $services = [],
        public ?float $slowStepShare = null,
        public PerformanceSettings $performance = new PerformanceSettings,
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
        } catch (JsonException $jsonException) {
            throw InvalidConfig::unreadable($path, $jsonException->getMessage());
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

        $ratchet = is_array($data['ratchet'] ?? null) ? $data['ratchet'] : [];
        Ratchet::validate($ratchet, $path);

        return new self(
            profile: $profile,
            mode: $mode,
            downstream: self::stringList($data['downstream'] ?? []),
            allowLaravel: self::stringList($data['allow_laravel'] ?? []),
            ratchet: Ratchet::fromArray($ratchet),
            protected: array_key_exists('protected', $data) ? self::stringList($data['protected']) : self::DEFAULT_PROTECTED,
            observed: self::stringList($data['observed'] ?? []),
            allowCommands: self::commandExemptions($data['allow_commands'] ?? [], $path),
            allowRules: self::ruleExemptions($data['allow_rules'] ?? [], $path),
            mutation: MutationSettings::fromArray($data['mutation'] ?? null, $path),
            npmAudit: NpmAuditSettings::fromArray($data['npm_audit'] ?? null, $path),
            services: self::services($data['services'] ?? [], $path),
            slowStepShare: self::slowStepShare($data['slow_step_share'] ?? null, $path),
            performance: PerformanceSettings::fromArray($data['performance'] ?? null, $path),
        );
    }

    /**
     * The same configuration with other mutation settings (a scope the command line chose for one run).
     */
    public function withMutation(MutationSettings $mutation): self
    {
        return new self($this->profile, $this->mode, $this->downstream, $this->allowLaravel, $this->ratchet, $this->protected, $this->observed, $this->allowCommands, $this->allowRules, $mutation, $this->npmAudit, $this->services, $this->slowStepShare, $this->performance);
    }

    public function withRatchet(Ratchet $ratchet): self
    {
        return new self($this->profile, $this->mode, $this->downstream, $this->allowLaravel, $ratchet, $this->protected, $this->observed, $this->allowCommands, $this->allowRules, $this->mutation, $this->npmAudit, $this->services, $this->slowStepShare, $this->performance);
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
            'observed' => $this->observed,
            'allow_commands' => array_map(static fn (CommandExemption $exemption): array => $exemption->toArray(), $this->allowCommands),
            'allow_rules' => array_map(static fn (RuleExemption $exemption): array => $exemption->toArray(), $this->allowRules),
            'ratchet' => $this->ratchet->toArray(),
            'mutation' => $this->mutation->toArray(),
            'npm_audit' => $this->npmAudit->toArray(),
            'services' => $this->services,
            'slow_step_share' => $this->slowStepShare,
            'performance' => $this->performance->toArray(),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Writes the file. A top-level key this version does not know (written by a newer harness, or by hand)
     * is carried over from the file on disk exactly as it was: saving never loses a key.
     */
    public function save(string $root): void
    {
        file_put_contents(
            self::path($root),
            json_encode($this->toArray() + self::unknownKeys($root), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
    }

    /**
     * The top-level keys of the file on disk that `fromArray()` does not read, or none when the file is absent or unreadable.
     *
     * @return array<string, mixed>
     */
    private static function unknownKeys(string $root): array
    {
        if (! is_file(self::path($root))) {
            return [];
        }

        $data = json_decode((string) file_get_contents(self::path($root)), true);

        if (! is_array($data)) {
            return [];
        }

        $unknown = [];

        foreach ($data as $key => $value) {
            if (! in_array($key, self::KNOWN_KEYS, true)) {
                $unknown[(string) $key] = $value;
            }
        }

        return $unknown;
    }

    /**
     * @return list<CommandExemption>
     *
     * @throws InvalidConfig
     */
    private static function commandExemptions(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $entry): CommandExemption => CommandExemption::fromArray(is_array($entry) ? $entry : [], $path),
            $value,
        ));
    }

    /**
     * @return list<RuleExemption>
     *
     * @throws InvalidConfig
     */
    private static function ruleExemptions(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            return [];
        }

        $whole = [];
        $partial = [];
        $exemptions = [];

        foreach ($value as $entry) {
            $exemption = RuleExemption::fromArray(is_array($entry) ? $entry : [], $path);

            if (! $exemption->isPartial() && array_key_exists($exemption->rule, $whole)) {
                throw InvalidConfig::duplicateRuleExemption($path, $exemption->rule);
            }

            if (($exemption->isPartial() && array_key_exists($exemption->rule, $whole)) || (! $exemption->isPartial() && array_key_exists($exemption->rule, $partial))) {
                throw InvalidConfig::ruleExemptionConflict($path, $exemption->rule);
            }

            $exemption->isPartial() ? $partial[$exemption->rule] = true : $whole[$exemption->rule] = true;
            $exemptions[] = $exemption;
        }

        return $exemptions;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidConfig
     */
    private static function services(mixed $value, string $path): array
    {
        $services = [];

        foreach (is_array($value) ? $value : [] as $service) {
            if (! is_string($service) || ! in_array($service, self::SERVICES, true)) {
                throw InvalidConfig::unknownService($path, $service);
            }

            $services[$service] = $service;
        }

        return array_values($services);
    }

    /**
     * The share of the total time above which a step is flagged as slow: null when absent.
     *
     * @throws InvalidConfig
     */
    private static function slowStepShare(mixed $value, string $path): ?float
    {
        if ($value === null) {
            return null;
        }

        if ((! is_int($value) && ! is_float($value)) || $value <= 0 || $value > 1) {
            throw InvalidConfig::slowStepShare($path, $value);
        }

        return (float) $value;
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
