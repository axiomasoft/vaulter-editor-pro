<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Validated runtime configuration options for `vaulter-editor-pro-review`.
 *
 * The section is closed: an unknown key or a value of the wrong type is reported as an
 * {@see InvalidConfiguration} naming every offending path, so a typo in a published config
 * file fails the doctor check instead of silently running with the default.
 */
#[Api(since: '1.0')]
final readonly class ReviewOptions implements OptionsFactory
{
    public const int MIN_SELECTOR_BYTES = 256;

    public const int MIN_SELECTOR_DEPTH = 2;

    public const int MIN_QUOTE_BYTES = 64;

    /**
     * @param  int<1, max>  $maxSelectorBytes
     * @param  int<1, max>  $maxSelectorDepth
     * @param  int<1, max>  $maxQuoteBytes
     */
    public function __construct(
        public bool $enforceRetention = true,
        public int $maxSelectorBytes = 16384,
        public int $maxSelectorDepth = 16,
        public int $maxQuoteBytes = 4096,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     *
     * @throws InvalidConfiguration when a key is unknown or a value is out of range
     */
    public static function fromArray(array $config, string $path = 'vaulter-editor-pro-review'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['pins', 'anchors'], $path);

        $pins = self::section($config, 'pins', ['enforce_retention'], $path, $validator);
        $anchors = self::section($config, 'anchors', ['max_selector_bytes', 'max_selector_depth', 'max_quote_bytes'], $path, $validator);

        $defaults = new self;
        $enforceRetention = array_key_exists('enforce_retention', $pins) ? $pins['enforce_retention'] : $defaults->enforceRetention;
        if (! is_bool($enforceRetention)) {
            $validator->add($path.'.pins.enforce_retention', 'must be a boolean.');
            $enforceRetention = $defaults->enforceRetention;
        }

        $options = new self(
            enforceRetention: $enforceRetention,
            maxSelectorBytes: self::atLeast($anchors, 'max_selector_bytes', self::MIN_SELECTOR_BYTES, $defaults->maxSelectorBytes, $path.'.anchors', $validator),
            maxSelectorDepth: self::atLeast($anchors, 'max_selector_depth', self::MIN_SELECTOR_DEPTH, $defaults->maxSelectorDepth, $path.'.anchors', $validator),
            maxQuoteBytes: self::atLeast($anchors, 'max_quote_bytes', self::MIN_QUOTE_BYTES, $defaults->maxQuoteBytes, $path.'.anchors', $validator),
        );

        $validator->failIfAny();

        return $options;
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private static function section(array $config, string $name, array $keys, string $path, ConfigurationValidator $validator): array
    {
        $section = array_key_exists($name, $config) ? $config[$name] : [];
        if (! is_array($section)) {
            $validator->add($path.'.'.$name, 'must be an array.');

            return [];
        }
        $validator->rejectUnknownKeys($section, $keys, $path.'.'.$name);

        return $section;
    }

    /**
     * @param  array<array-key, mixed>  $section
     * @param  int<1, max>  $minimum
     * @param  int<1, max>  $default
     * @return int<1, max>
     */
    private static function atLeast(array $section, string $key, int $minimum, int $default, string $sectionPath, ConfigurationValidator $validator): int
    {
        $value = array_key_exists($key, $section) ? $section[$key] : $default;
        if (! is_int($value) || $value < $minimum) {
            $validator->add($sectionPath.'.'.$key, sprintf('must be an integer of at least %d.', $minimum));

            return $default;
        }

        return $value;
    }
}
