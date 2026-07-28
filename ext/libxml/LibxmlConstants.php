<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

/**
 * libxml2 parse/error/version constants (php-src ext/libxml/libxml.c / libxml.stub.php;
 * issues #6058, #11885, #24051).
 *
 * @see libxml2 parser.h — XML_PARSE_*
 * @see libxml2 xmlversion.h — LIBXML_VERSION / LIBXML_DOTTED_VERSION
 * @see libxml2 xmlParserVersion — LIBXML_LOADED_VERSION
 */
final class LibxmlConstants
{
    /**
     * Pinned libxml2 identity for php-compiler:22.04-dev / Ubuntu 22.04 (libxml2 2.9.14).
     * Used when host Zend does not expose the trio (AOT/self-host); DOM schema/RelaxNG FFI
     * loads the same libxml2.so.2 family ({@see \PHPCompiler\ext\dom\VmDomValidationNative}).
     */
    public const LIBXML_VERSION = 20914;

    public const LIBXML_DOTTED_VERSION = '2.9.14';

    /** php-src: `(char *)xmlParserVersion` — numeric string, not dotted. */
    public const LIBXML_LOADED_VERSION = '20914';

    public const LIBXML_ERR_NONE = 0;

    public const LIBXML_ERR_WARNING = 1;

    public const LIBXML_ERR_ERROR = 2;

    public const LIBXML_ERR_FATAL = 3;

    /** libxml2 XML_PARSE_* flags exposed to userland. */
    public const LIBXML_RECOVER = 1;

    public const LIBXML_NOENT = 2;

    public const LIBXML_DTDLOAD = 4;

    public const LIBXML_DTDATTR = 8;

    public const LIBXML_DTDVALID = 16;

    public const LIBXML_NOERROR = 32;

    public const LIBXML_NOWARNING = 64;

    public const LIBXML_PEDANTIC = 128;

    public const LIBXML_NOBLANKS = 256;

    public const LIBXML_XINCLUDE = 1024;

    public const LIBXML_NONET = 2048;

    public const LIBXML_NSCLEAN = 8192;

    public const LIBXML_NOCDATA = 16384;

    public const LIBXML_COMPACT = 65536;

    public const LIBXML_PARSEHUGE = 524288;

    public const LIBXML_BIGLINES = 4194304;

    public const LIBXML_HTML_NODEFDTD = 4;

    public const LIBXML_HTML_NOIMPLIED = 8192;

    public const LIBXML_NOXMLDECL = 2;

    public const LIBXML_NOEMPTYTAG = 4;

    public const LIBXML_SCHEMA_CREATE = 1;

    /**
     * All userland libxml constants registered at module init / get_defined_constants.
     *
     * @return array<string, int|string>
     */
    public static function registeredConstants(): array
    {
        return self::versionConstants() + [
            'LIBXML_ERR_NONE' => self::LIBXML_ERR_NONE,
            'LIBXML_ERR_WARNING' => self::LIBXML_ERR_WARNING,
            'LIBXML_ERR_ERROR' => self::LIBXML_ERR_ERROR,
            'LIBXML_ERR_FATAL' => self::LIBXML_ERR_FATAL,
        ] + self::parseFlagConstants();
    }

    /**
     * LIBXML_VERSION / LIBXML_DOTTED_VERSION / LIBXML_LOADED_VERSION (php-src libxml.c; #24051).
     *
     * Prefer host Zend values when present so VM matches the linked libxml2 on the same box
     * (php-src-strict). Fall back to the pinned Ubuntu 22.04 identity for AOT/self-host.
     *
     * @return array<string, int|string>
     */
    public static function versionConstants(): array
    {
        if (
            \defined('LIBXML_VERSION')
            && \defined('LIBXML_DOTTED_VERSION')
            && \defined('LIBXML_LOADED_VERSION')
        ) {
            return [
                'LIBXML_VERSION' => (int) \constant('LIBXML_VERSION'),
                'LIBXML_DOTTED_VERSION' => (string) \constant('LIBXML_DOTTED_VERSION'),
                'LIBXML_LOADED_VERSION' => (string) \constant('LIBXML_LOADED_VERSION'),
            ];
        }

        return [
            'LIBXML_VERSION' => self::LIBXML_VERSION,
            'LIBXML_DOTTED_VERSION' => self::LIBXML_DOTTED_VERSION,
            'LIBXML_LOADED_VERSION' => self::LIBXML_LOADED_VERSION,
        ];
    }

    /** @return array<string, int> */
    public static function parseFlagConstants(): array
    {
        return [
            'LIBXML_RECOVER' => self::LIBXML_RECOVER,
            'LIBXML_NOENT' => self::LIBXML_NOENT,
            'LIBXML_DTDLOAD' => self::LIBXML_DTDLOAD,
            'LIBXML_DTDATTR' => self::LIBXML_DTDATTR,
            'LIBXML_DTDVALID' => self::LIBXML_DTDVALID,
            'LIBXML_NOERROR' => self::LIBXML_NOERROR,
            'LIBXML_NOWARNING' => self::LIBXML_NOWARNING,
            'LIBXML_PEDANTIC' => self::LIBXML_PEDANTIC,
            'LIBXML_NOBLANKS' => self::LIBXML_NOBLANKS,
            'LIBXML_XINCLUDE' => self::LIBXML_XINCLUDE,
            'LIBXML_NONET' => self::LIBXML_NONET,
            'LIBXML_NSCLEAN' => self::LIBXML_NSCLEAN,
            'LIBXML_NOCDATA' => self::LIBXML_NOCDATA,
            'LIBXML_COMPACT' => self::LIBXML_COMPACT,
            'LIBXML_PARSEHUGE' => self::LIBXML_PARSEHUGE,
            'LIBXML_BIGLINES' => self::LIBXML_BIGLINES,
            'LIBXML_HTML_NODEFDTD' => self::LIBXML_HTML_NODEFDTD,
            'LIBXML_HTML_NOIMPLIED' => self::LIBXML_HTML_NOIMPLIED,
            'LIBXML_NOXMLDECL' => self::LIBXML_NOXMLDECL,
            'LIBXML_NOEMPTYTAG' => self::LIBXML_NOEMPTYTAG,
            'LIBXML_SCHEMA_CREATE' => self::LIBXML_SCHEMA_CREATE,
        ];
    }
}
