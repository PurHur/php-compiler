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
        // Unbound catalog: return msgid without NestedJIT'ing VmGettextPure (#27391).
        if ('' === (self::$domainPaths[self::$currentDomain] ?? '')) {
            return $msgid;
        }

        return VmGettextPure::translate(self::$currentDomain, $msgid, self::LC_MESSAGES);
    }

    public static function dgettext(string $domain, string $msgid): string
    {
        self::rejectEmptyDomain($domain, 'dgettext');
        if ('' === (self::$domainPaths[$domain] ?? '')) {
            return $msgid;
        }

        return VmGettextPure::translate($domain, $msgid, self::LC_MESSAGES);
    }

    public static function dcgettext(string $domain, string $msgid, int $category): string
    {
        self::rejectEmptyDomain($domain, 'dcgettext');
        if ('' === (self::$domainPaths[$domain] ?? '')) {
            return $msgid;
        }

        return VmGettextPure::translate($domain, $msgid, $category);
    }

    public static function ngettext(string $msgid1, string $msgid2, int $n): string
    {
        return self::dngettext(self::$currentDomain, $msgid1, $msgid2, $n);
    }

    public static function dngettext(string $domain, string $msgid1, string $msgid2, int $n): string
    {
        self::rejectEmptyDomain($domain, 'dngettext');
        if ('' === (self::$domainPaths[$domain] ?? '')) {
            return 1 === $n ? $msgid1 : $msgid2;
        }

        return VmGettextPure::translatePlural($domain, $msgid1, $msgid2, $n, self::LC_MESSAGES);
    }

    public static function dcngettext(
        string $domain,
        string $msgid1,
        string $msgid2,
        int $n,
        int $category
    ): string {
        self::rejectEmptyDomain($domain, 'dcngettext');
        if ('' === (self::$domainPaths[$domain] ?? '')) {
            return 1 === $n ? $msgid1 : $msgid2;
        }

        return VmGettextPure::translatePlural($domain, $msgid1, $msgid2, $n, $category);
    }

    public static function bindtextdomain(string $domain, ?string $directory): string|false
    {
        // PHP_GETTEXT_DOMAIN_LENGTH_CHECK — empty domain ValueError after soft-null (#21581).
        self::rejectEmptyDomain($domain, 'bindtextdomain');
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
        self::rejectEmptyDomain($domain, 'textdomain');

        self::$currentDomain = $domain;

        return $previous;
    }

    public static function bindTextdomainCodeset(string $domain, ?string $codeset): string|false
    {
        self::rejectEmptyDomain($domain, 'bind_textdomain_codeset');
        $previous = self::$domainCodesets[$domain] ?? '';
        if (null === $codeset) {
            return '' === $previous ? false : $previous;
        }

        self::$domainCodesets[$domain] = $codeset;

        return '' === $previous ? $codeset : $previous;
    }

    /**
     * php-src PHP_GETTEXT_DOMAIN_LENGTH_CHECK — zend_argument_must_not_be_empty_error (#21581).
     *
     * @throws \ValueError when $domain is empty
     */
    private static function rejectEmptyDomain(string $domain, string $function): void
    {
        if ('' === $domain) {
            // String concat — NestedJIT must not depend on __compiler_sprintf (#27391).
            throw new \ValueError(
                $function.'(): Argument #1 ($domain) must not be empty'
            );
        }
    }

    public static function defaultCategory(): int
    {
        return self::LC_MESSAGES;
    }
}
