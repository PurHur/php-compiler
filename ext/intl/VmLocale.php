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
