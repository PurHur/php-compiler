<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

/**
 * libxml2 parse/error constants (php-src ext/libxml/libxml.c; issues #6058, #11885).
 *
 * @see libxml2 parser.h — XML_PARSE_*
 */
final class LibxmlConstants
{
    public const LIBXML_ERR_NONE = 0;

    public const LIBXML_ERR_WARNING = 1;

    public const LIBXML_ERR_ERROR = 2;

    public const LIBXML_ERR_FATAL = 3;

    /** libxml2 XML_PARSE_* flags exposed to userland. */
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
     * All userland parse-flag constants registered at module init.
     *
     * @return array<string, int>
     */
    public static function parseFlagConstants(): array
    {
        return [
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
