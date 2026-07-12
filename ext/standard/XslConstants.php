<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * XSL extension constants (php-src ext/xsl/php_xsl.c; #17799).
 *
 * Registered from ext/xsl when host libxslt is available (#3665, #17799).
 */
final class XslConstants
{
    public const XSL_CLONE_AUTO = 0;
    public const XSL_CLONE_NEVER = -1;
    public const XSL_CLONE_ALWAYS = 1;
    public const XSL_SECPREF_NONE = 0;
    public const XSL_SECPREF_READ_FILE = 2;
    public const XSL_SECPREF_WRITE_FILE = 4;
    public const XSL_SECPREF_CREATE_DIRECTORY = 8;
    public const XSL_SECPREF_READ_NETWORK = 16;
    public const XSL_SECPREF_WRITE_NETWORK = 32;
    public const XSL_SECPREF_DEFAULT = 44;
    public const LIBXSLT_VERSION = 10134;
    public const LIBEXSLT_VERSION = 820;

    /** @return array<string, int|string> */
    public static function registeredConstants(): array
    {
        return [
            'XSL_CLONE_AUTO' => self::XSL_CLONE_AUTO,
            'XSL_CLONE_NEVER' => self::XSL_CLONE_NEVER,
            'XSL_CLONE_ALWAYS' => self::XSL_CLONE_ALWAYS,
            'XSL_SECPREF_NONE' => self::XSL_SECPREF_NONE,
            'XSL_SECPREF_READ_FILE' => self::XSL_SECPREF_READ_FILE,
            'XSL_SECPREF_WRITE_FILE' => self::XSL_SECPREF_WRITE_FILE,
            'XSL_SECPREF_CREATE_DIRECTORY' => self::XSL_SECPREF_CREATE_DIRECTORY,
            'XSL_SECPREF_READ_NETWORK' => self::XSL_SECPREF_READ_NETWORK,
            'XSL_SECPREF_WRITE_NETWORK' => self::XSL_SECPREF_WRITE_NETWORK,
            'XSL_SECPREF_DEFAULT' => self::XSL_SECPREF_DEFAULT,
            'LIBXSLT_VERSION' => self::LIBXSLT_VERSION,
            'LIBXSLT_DOTTED_VERSION' => '1.1.34',
            'LIBEXSLT_VERSION' => self::LIBEXSLT_VERSION,
            'LIBEXSLT_DOTTED_VERSION' => '1.1.34',
        ];
    }
}
