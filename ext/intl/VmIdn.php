<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * IDNA conversion (php-src ext/intl/idn/idn.c; issue #6169).
 *
 * Prefer host ext/intl when present; otherwise thin FFI to libidn2 (UTS46 / IDNA2008).
 */
final class VmIdn
{
    public const IDNA_DEFAULT = 0;

    public const IDNA_ALLOW_UNASSIGNED = 1;

    public const IDNA_USE_STD3_RULES = 2;

    public const IDNA_CHECK_BIDI = 4;

    public const IDNA_CHECK_CONTEXTJ = 8;

    public const IDNA_NONTRANSITIONAL_TO_ASCII = 16;

    public const IDNA_NONTRANSITIONAL_TO_UNICODE = 32;

    public const IDNA_CHECK_CONTEXTO = 64;

    /** php-src INTL_IDNA_VARIANT_2003 (removed as usable variant in PHP 7.4+) */
    public const VARIANT_2003 = 0;

    /** php-src INTL_IDNA_VARIANT_UTS46 */
    public const VARIANT_UTS46 = 1;

    /** libidn2 IDN2_NFC_INPUT */
    private const IDN2_NFC_INPUT = 1;

    /** libidn2 IDN2_NONTRANSITIONAL */
    private const IDN2_NONTRANSITIONAL = 8;

    /** libidn2 IDN2_USE_STD3_ASCII_RULES */
    private const IDN2_USE_STD3_ASCII_RULES = 32;

    /** libidn2 IDN2_ALLOW_UNASSIGNED */
    private const IDN2_ALLOW_UNASSIGNED = 64;

    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'IDNA_DEFAULT' => self::IDNA_DEFAULT,
            'IDNA_ALLOW_UNASSIGNED' => self::IDNA_ALLOW_UNASSIGNED,
            'IDNA_USE_STD3_RULES' => self::IDNA_USE_STD3_RULES,
            'IDNA_CHECK_BIDI' => self::IDNA_CHECK_BIDI,
            'IDNA_CHECK_CONTEXTJ' => self::IDNA_CHECK_CONTEXTJ,
            'IDNA_NONTRANSITIONAL_TO_ASCII' => self::IDNA_NONTRANSITIONAL_TO_ASCII,
            'IDNA_NONTRANSITIONAL_TO_UNICODE' => self::IDNA_NONTRANSITIONAL_TO_UNICODE,
            'IDNA_CHECK_CONTEXTO' => self::IDNA_CHECK_CONTEXTO,
            'INTL_IDNA_VARIANT_2003' => self::VARIANT_2003,
            'INTL_IDNA_VARIANT_UTS46' => self::VARIANT_UTS46,
        ];
    }

    public static function available(): bool
    {
        return \function_exists('idn_to_ascii') || null !== self::ffi();
    }

    /**
     * @param-out array{result: string, isTransitionalDifferent: bool, errors: int}|null $info
     */
    public static function toAscii(string $domain, int $flags, int $variant, ?array &$info): string|false
    {
        return self::convert($domain, $flags, $variant, true, $info);
    }

    /**
     * @param-out array{result: string, isTransitionalDifferent: bool, errors: int}|null $info
     */
    public static function toUtf8(string $domain, int $flags, int $variant, ?array &$info): string|false
    {
        return self::convert($domain, $flags, $variant, false, $info);
    }

    /**
     * @param-out array{result: string, isTransitionalDifferent: bool, errors: int}|null $info
     */
    private static function convert(
        string $domain,
        int $flags,
        int $variant,
        bool $toAscii,
        ?array &$info
    ): string|false {
        IntlError::clear();
        $info = null;

        $fn = $toAscii ? 'idn_to_ascii' : 'idn_to_utf8';
        if (self::VARIANT_UTS46 !== $variant) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                $fn.': invalid variant, must be INTL_IDNA_VARIANT_UTS46'
            );

            return false;
        }
        if ('' === $domain) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                $fn.': empty domain name'
            );

            return false;
        }
        if (\strlen($domain) > 2147483646) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                $fn.': domain name too large'
            );

            return false;
        }

        $converted = self::convertDomain($domain, $flags, $toAscii);
        if (null === $converted) {
            $info = [
                'result' => '',
                'isTransitionalDifferent' => false,
                'errors' => 1,
            ];

            return false;
        }

        $info = [
            'result' => $converted,
            'isTransitionalDifferent' => false,
            'errors' => 0,
        ];

        return $converted;
    }

    private static function convertDomain(string $domain, int $flags, bool $toAscii): ?string
    {
        $hostFn = $toAscii ? 'idn_to_ascii' : 'idn_to_utf8';
        if (\function_exists($hostFn)) {
            $hostInfo = [];
            $result = $hostFn($domain, $flags, self::VARIANT_UTS46, $hostInfo);
            if (false === $result) {
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    $hostFn.'(): failed to convert name'
                );

                return null;
            }

            return $result;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                ($toAscii ? 'idn_to_ascii' : 'idn_to_utf8').'(): libidn2 is not available'
            );

            return null;
        }

        $idn2Flags = self::mapFlags($flags);
        $buf = $ffi->new('char*');
        $fn = $toAscii ? 'idn2_to_ascii_8z' : 'idn2_to_unicode_8z8z';
        $rc = (int) $ffi->$fn($domain, \FFI::addr($buf), $idn2Flags);
        if (0 !== $rc) {
            $msg = self::ffiStrerror($ffi, $rc);
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                ($toAscii ? 'idn_to_ascii' : 'idn_to_utf8').': '.$msg
            );

            return null;
        }
        $out = \FFI::string($buf);
        $ffi->idn2_free($buf);

        return $out;
    }

    private static function mapFlags(int $flags): int
    {
        // UTS46 nontransitional + NFC input matches php-src uidna_openUTS46(UIDNA_DEFAULT).
        $idn2 = self::IDN2_NFC_INPUT | self::IDN2_NONTRANSITIONAL;
        if (0 !== ($flags & self::IDNA_USE_STD3_RULES)) {
            $idn2 |= self::IDN2_USE_STD3_ASCII_RULES;
        }
        if (0 !== ($flags & self::IDNA_ALLOW_UNASSIGNED)) {
            $idn2 |= self::IDN2_ALLOW_UNASSIGNED;
        }

        return $idn2;
    }

    private static function ffiStrerror(\FFI $ffi, int $rc): string
    {
        try {
            $p = $ffi->idn2_strerror($rc);

            return null !== $p ? (string) \FFI::string($p) : 'failed to convert name';
        } catch (\Throwable) {
            return 'failed to convert name';
        }
    }

    /** @return \FFI|null */
    private static function ffi()
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\class_exists(\FFI::class, false) && !\extension_loaded('FFI')) {
            self::$ffiUnavailable = true;

            return null;
        }
        try {
            self::$ffi = \FFI::cdef(<<<'C'
int idn2_to_ascii_8z(const char *input, char **output, int flags);
int idn2_to_unicode_8z8z(const char *input, char **output, int flags);
void idn2_free(void *ptr);
const char *idn2_strerror(int rc);
C, 'libidn2.so.0');
        } catch (\Throwable) {
            self::$ffiUnavailable = true;
            self::$ffi = null;

            return null;
        }

        return self::$ffi;
    }

    public static function writeIdnaInfo(Frame $frame, int $argIndex, array $info): void
    {
        if (!isset($frame->calledArgs[$argIndex])) {
            return;
        }
        $ht = new HashTable();
        $result = new Variable();
        $result->string((string) $info['result']);
        $ht->add('result', $result);
        $trans = new Variable();
        $trans->bool((bool) $info['isTransitionalDifferent']);
        $ht->add('isTransitionalDifferent', $trans);
        $errors = new Variable();
        $errors->int((int) $info['errors']);
        $ht->add('errors', $errors);
        $frame->calledArgs[$argIndex]->resolveIndirect()->array($ht);
    }
}
