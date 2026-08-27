<?php

declare(strict_types=1);

namespace Meridian\I18n;

/**
 * Minimal message-catalog translator. Catalogs live in
 * translations/{locale}.php as nested arrays; keys use dot notation.
 * German is the product's default and the fallback for missing keys.
 */
final class Translator
{
    public const LOCALES = ['de', 'en'];
    public const DEFAULT = 'de';

    /** @var array<string, mixed> */
    private readonly array $messages;
    /** @var array<string, mixed> */
    private readonly array $fallback;

    public function __construct(
        public readonly string $locale,
        private readonly string $translationsDir,
    ) {
        $this->messages = require $translationsDir . '/' . $locale . '.php';
        $this->fallback = $locale === self::DEFAULT
            ? $this->messages
            : require $translationsDir . '/' . self::DEFAULT . '.php';
    }

    public static function resolveLocale(?string $requested): string
    {
        return in_array($requested, self::LOCALES, true) ? $requested : self::DEFAULT;
    }

    /** Translates a dot-notation key; extra args are sprintf'd in. */
    public function t(string $key, int|float|string ...$args): string
    {
        $value = $this->get($key);
        if (!is_string($value)) {
            return $key;
        }

        return $args === [] ? $value : vsprintf($value, $args);
    }

    /** Raw catalog lookup — returns strings, lists, or null. */
    public function get(string $key): mixed
    {
        return $this->walk($this->messages, $key) ?? $this->walk($this->fallback, $key);
    }

    /** @param array<string, mixed> $messages */
    private function walk(array $messages, string $key): mixed
    {
        $node = $messages;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }
}
