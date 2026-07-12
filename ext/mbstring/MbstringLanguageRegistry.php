<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mbstring NLS language names (php-src ext/mbstring/libmbfl/nls; #4636).
 */
final class MbstringLanguageRegistry
{
    /** @var array<string, string> lowercase alias => canonical getter name */
    private const CANONICAL = [
        'neutral' => 'neutral',
        'uni' => 'uni',
        'english' => 'English',
        'en' => 'English',
        'german' => 'German',
        'de' => 'German',
        'japanese' => 'Japanese',
        'korean' => 'Korean',
        'russian' => 'Russian',
        'simplified chinese' => 'Simplified Chinese',
        'traditional chinese' => 'Traditional Chinese',
        'armenian' => 'Armenian',
        'ukrainian' => 'Ukrainian',
        'turkish' => 'Turkish',
    ];

    public static function resolve(string $language): ?string
    {
        $key = strtolower($language);

        return self::CANONICAL[$key] ?? null;
    }

    public static function assertValid(string $language, string $function, int $argIndex): string
    {
        $canonical = self::resolve($language);
        if (null === $canonical) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($language) must be a valid language, "%s" given',
                $function,
                $argIndex + 1,
                $language
            ));
        }

        return $canonical;
    }
}
