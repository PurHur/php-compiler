<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

/**
 * libintl(3) via FFI with PHP fallback state (php-src ext/gettext/gettext.c; #3449, #6608).
 *
 * When libintl is unavailable, translate helpers return msgid(s) and domain binding
 * is tracked in-process so bindtextdomain/textdomain still return sensible strings.
 */
final class VmGettextNative
{
    private const LC_MESSAGES = 5;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    private static string $currentDomain = 'messages';

    /** @var array<string, string> */
    private static array $domainPaths = [];

    /** @var array<string, string> */
    private static array $domainCodesets = [];

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function gettext(string $msgid): string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return $msgid;
        }

        return self::ffiString($ffi->gettext(self::copyCString($ffi, $msgid)), $msgid);
    }

    public static function dgettext(string $domain, string $msgid): string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return $msgid;
        }

        return self::ffiString(
            $ffi->dgettext(self::copyCString($ffi, $domain), self::copyCString($ffi, $msgid)),
            $msgid
        );
    }

    public static function dcgettext(string $domain, string $msgid, int $category): string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return $msgid;
        }

        return self::ffiString(
            $ffi->dcgettext(
                self::copyCString($ffi, $domain),
                self::copyCString($ffi, $msgid),
                $category
            ),
            $msgid
        );
    }

    public static function dngettext(string $domain, string $msgid1, string $msgid2, int $n): string
    {
        $fallback = 1 === $n ? $msgid1 : $msgid2;
        $ffi = self::ffi();
        if (null === $ffi) {
            return $fallback;
        }

        return self::ffiString(
            $ffi->dngettext(
                self::copyCString($ffi, $domain),
                self::copyCString($ffi, $msgid1),
                self::copyCString($ffi, $msgid2),
                $n
            ),
            $fallback
        );
    }

    public static function dcngettext(
        string $domain,
        string $msgid1,
        string $msgid2,
        int $n,
        int $category
    ): string {
        $fallback = 1 === $n ? $msgid1 : $msgid2;
        $ffi = self::ffi();
        if (null === $ffi) {
            return $fallback;
        }

        return self::ffiString(
            $ffi->dcngettext(
                self::copyCString($ffi, $domain),
                self::copyCString($ffi, $msgid1),
                self::copyCString($ffi, $msgid2),
                $n,
                $category
            ),
            $fallback
        );
    }

    public static function bindtextdomain(string $domain, ?string $directory): string|false
    {
        $previous = self::$domainPaths[$domain] ?? '';
        if (null === $directory) {
            return '' === $previous ? false : $previous;
        }

        $ffi = self::ffi();
        if (null !== $ffi) {
            $result = $ffi->bindtextdomain(
                self::copyCString($ffi, $domain),
                self::copyCString($ffi, $directory)
            );
            if (null === $result) {
                return false;
            }
            $bound = self::ffiString($result, $directory);
            self::$domainPaths[$domain] = $bound;

            return $bound;
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

        $ffi = self::ffi();
        if (null !== $ffi) {
            $result = $ffi->textdomain(self::copyCString($ffi, $domain));
            if (null === $result) {
                return false;
            }
            $active = self::ffiString($result, $domain);
            self::$currentDomain = $active;

            return $active;
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

        $ffi = self::ffi();
        if (null !== $ffi) {
            $result = $ffi->bind_textdomain_codeset(
                self::copyCString($ffi, $domain),
                self::copyCString($ffi, $codeset)
            );
            if (null === $result) {
                return false;
            }
            $stored = self::ffiString($result, $codeset);
            self::$domainCodesets[$domain] = $stored;

            return $stored;
        }

        self::$domainCodesets[$domain] = $codeset;

        return '' === $previous ? $codeset : $previous;
    }

    public static function defaultCategory(): int
    {
        return self::LC_MESSAGES;
    }

    private static function ffiString(mixed $ptr, string $fallback): string
    {
        if (null === $ptr) {
            return $fallback;
        }
        if (\is_string($ptr)) {
            return '' === $ptr ? $fallback : $ptr;
        }
        if ($ptr instanceof \FFI\CData) {
            try {
                $s = \FFI::string($ptr);

                return '' === $s ? $fallback : $s;
            } catch (\Throwable) {
                return $fallback;
            }
        }

        return $fallback;
    }

    private static function copyCString(\FFI $ffi, string $value): \FFI\CData
    {
        $len = \strlen($value);
        $buf = $ffi->new('char['.($len + 1).']', false);
        if ($len > 0) {
            \FFI::memcpy($buf, $value, $len);
        }
        $buf[$len] = "\0";

        return $buf;
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')
            || !\in_array(strtolower((string) \ini_get('ffi.enable')), ['1', 'true', 'preload'], true)) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
char *gettext(const char *msgid);
char *bindtextdomain(const char *domain, const char *dirname);
char *textdomain(const char *domain);
char *dgettext(const char *domain, const char *msgid);
char *dcgettext(const char *domain, const char *msgid, int category);
char *dngettext(const char *domain, const char *msgid1, const char *msgid2, unsigned long int n);
char *dcngettext(const char *domain, const char *msgid1, const char *msgid2, unsigned long int n, int category);
char *bind_textdomain_codeset(const char *domain, const char *codeset);
CDEF;

        foreach (['libintl.so.8', 'libc.so.6'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);
                if (null !== self::$ffi) {
                    return self::$ffi;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }
}
