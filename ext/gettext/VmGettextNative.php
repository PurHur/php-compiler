<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

/**
 * VM gettext builtins via pure PHP MO catalogs (#3449, #6608, #8952).
 *
 * Domain binding state + {@see VmGettextPure} MO reader — no host libc FFI.
 * php-src: ext/gettext/gettext.c
 */
final class VmGettextNative
{
    private const LC_MESSAGES = 5;

    private static string $currentDomain = 'messages';

    /** @var array<string, string> */
    private static array $domainPaths = [];

    /** @var array<string, string> */
    private static array $domainCodesets = [];

    public static function available(): bool
    {
        return VmGettextPure::available();
    }

    public static function boundDirectory(string $domain): string
    {
        return self::$domainPaths[$domain] ?? '';
    }

    public static function gettext(string $msgid): string
    {
        return VmGettextPure::translate(self::$currentDomain, $msgid, self::LC_MESSAGES);
    }

    public static function dgettext(string $domain, string $msgid): string
    {
        return VmGettextPure::translate($domain, $msgid, self::LC_MESSAGES);
    }

    public static function dcgettext(string $domain, string $msgid, int $category): string
    {
        return VmGettextPure::translate($domain, $msgid, $category);
    }

    public static function dngettext(string $domain, string $msgid1, string $msgid2, int $n): string
    {
        return VmGettextPure::translatePlural($domain, $msgid1, $msgid2, $n, self::LC_MESSAGES);
    }

    public static function dcngettext(
        string $domain,
        string $msgid1,
        string $msgid2,
        int $n,
        int $category
    ): string {
        return VmGettextPure::translatePlural($domain, $msgid1, $msgid2, $n, $category);
    }

    public static function bindtextdomain(string $domain, ?string $directory): string|false
    {
        $previous = self::$domainPaths[$domain] ?? '';
        if (null === $directory) {
            return '' === $previous ? false : $previous;
        }

        self::$domainPaths[$domain] = $directory;

        return '' === $previous ? $directory : $previous;
    }

    public static function textdomain(?string $domain): string|false
    {
        $previous = self::$currentDomain;
        if (null === $domain) {
            return '' === $previous ? false : $previous;
        }

        self::$currentDomain = $domain;

        return $previous;
    }

    public static function bindTextdomainCodeset(string $domain, ?string $codeset): string|false
    {
        $previous = self::$domainCodesets[$domain] ?? '';
        if (null === $codeset) {
            return '' === $previous ? false : $previous;
        }

        self::$domainCodesets[$domain] = $codeset;

        return '' === $previous ? $codeset : $previous;
    }

    public static function defaultCategory(): int
    {
        return self::LC_MESSAGES;
    }
}
