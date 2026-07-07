<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * Process default BCP-47 locale id (php-src ext/intl/php_intl.c; issue #9576).
 *
 * v1: PHP-only store without ICU — uloc wiring deferred to full ext/intl (#11472).
 */
final class VmLocale
{
    private static ?string $default = null;

    public static function getDefault(): string
    {
        if (null === self::$default) {
            self::$default = self::detectSystemDefault();
        }

        return self::$default;
    }

    public static function setDefault(string $locale): bool
    {
        self::assertValidLocaleId($locale);
        self::$default = $locale;

        return true;
    }

    public static function resetDefaultForTests(): void
    {
        self::$default = null;
    }

    /**
     * locale_get_primary_language() — first BCP-47 subtag (php-src ext/intl/locale/locale_methods.c).
     */
    public static function getPrimaryLanguage(string $locale): string
    {
        $id = self::resolveLocaleOperand($locale);

        return self::parseBcp47Tags($id)['language'];
    }

    /**
     * locale_get_region() — region subtag when present (php-src ext/intl/locale/locale_methods.c).
     */
    public static function getRegion(string $locale): string
    {
        $id = self::resolveLocaleOperand($locale);

        return self::parseBcp47Tags($id)['region'];
    }

    /**
     * locale_get_script() — 4-letter script subtag when present (php-src ext/intl/locale/locale_methods.c).
     */
    public static function getScript(string $locale): string
    {
        $id = self::resolveLocaleOperand($locale);

        return self::parseBcp47Tags($id)['script'];
    }

    private static function resolveLocaleOperand(string $locale): string
    {
        if ('' === $locale) {
            return self::getDefault();
        }

        return $locale;
    }

    /**
     * @return array{language: string, script: string, region: string}
     */
    private static function parseBcp47Tags(string $locale): array
    {
        $locale = str_replace('_', '-', $locale);
        $segments = explode('-', $locale);
        $language = '';
        $script = '';
        $region = '';
        if ([] === $segments || '' === $segments[0]) {
            return ['language' => $language, 'script' => $script, 'region' => $region];
        }
        $language = strtolower($segments[0]);
        $count = \count($segments);
        for ($i = 1; $i < $count; ++$i) {
            $part = $segments[$i];
            if ('' === $part) {
                continue;
            }
            if ('' === $script && 4 === \strlen($part) && ctype_alpha($part)) {
                $script = self::canonicalScriptTag($part);
                continue;
            }
            if ('' === $region
                && ((2 === \strlen($part) && ctype_alpha($part))
                    || (3 === \strlen($part) && ctype_digit($part)))) {
                $region = strtoupper($part);
            }
        }

        return ['language' => $language, 'script' => $script, 'region' => $region];
    }

    private static function canonicalScriptTag(string $script): string
    {
        $lower = strtolower($script);

        return strtoupper($lower[0]).substr($lower, 1);
    }

    private static function detectSystemDefault(): string
    {
        foreach (['LC_ALL', 'LANG', 'LC_MESSAGES'] as $var) {
            $val = getenv($var);
            if (!\is_string($val) || '' === $val) {
                continue;
            }
            if ('C' === $val || 'POSIX' === $val) {
                return 'en_US_POSIX';
            }
            $tag = explode('.', $val, 2)[0];
            $tag = str_replace('-', '_', $tag);
            if (self::isValidLocaleId($tag)) {
                return $tag;
            }
        }

        return 'en_US_POSIX';
    }

    private static function assertValidLocaleId(string $locale): void
    {
        if (!self::isValidLocaleId($locale)) {
            throw new \ValueError(
                'locale_set_default(): Argument #1 ($locale) must be a valid locale'
            );
        }
    }

    private static function isValidLocaleId(string $locale): bool
    {
        if ('' === $locale) {
            return false;
        }

        return 1 === preg_match(
            '/^[a-zA-Z][a-zA-Z0-9]*(?:_[a-zA-Z0-9]+)*(?:@[a-zA-Z0-9_=\\-\\.,]+)?$/',
            $locale
        );
    }
}
