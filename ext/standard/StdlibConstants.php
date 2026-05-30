<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Zend predefined constants for ext/standard (php-src basic_functions.c PHP_MINIT).
 *
 * @see ext/standard/basic_functions.c — STR_PAD_*, ENT_*, PHP_ROUND_*
 */
final class StdlibConstants
{
    /** str_pad() pad type (ext/standard/string.c). */
    public const STR_PAD_LEFT = 0;
    public const STR_PAD_RIGHT = 1;
    public const STR_PAD_BOTH = 2;

    /** htmlspecialchars() / htmlentities() flags (ext/standard/html.c). */
    public const ENT_COMPAT = 2;
    public const ENT_QUOTES = 3;
    public const ENT_NOQUOTES = 0;
    public const ENT_IGNORE = 4;
    public const ENT_SUBSTITUTE = 8;
    public const ENT_DISALLOWED = 128;
    public const ENT_HTML401 = 0;
    public const ENT_XML1 = 16;
    public const ENT_XHTML = 17;
    public const ENT_HTML5 = 48;

    /** round() mode flags (ext/standard/math.c). */
    public const PHP_ROUND_HALF_UP = 1;
    public const PHP_ROUND_HALF_DOWN = 2;
    public const PHP_ROUND_HALF_EVEN = 3;
    public const PHP_ROUND_HALF_ODD = 4;

    /** array_change_key_case() mode flags (ext/standard/array.c). */
    public const CASE_LOWER = 0;
    public const CASE_UPPER = 1;

    /** Lowercase name => int value for VM\Context::constantFetch(). */
    public const CORE_INT_BY_NAME = [
        'str_pad_left' => self::STR_PAD_LEFT,
        'str_pad_right' => self::STR_PAD_RIGHT,
        'str_pad_both' => self::STR_PAD_BOTH,
        'ent_compat' => self::ENT_COMPAT,
        'ent_quotes' => self::ENT_QUOTES,
        'ent_noquotes' => self::ENT_NOQUOTES,
        'ent_ignore' => self::ENT_IGNORE,
        'ent_substitute' => self::ENT_SUBSTITUTE,
        'ent_disallowed' => self::ENT_DISALLOWED,
        'ent_html401' => self::ENT_HTML401,
        'ent_xml1' => self::ENT_XML1,
        'ent_xhtml' => self::ENT_XHTML,
        'ent_html5' => self::ENT_HTML5,
        'php_round_half_up' => self::PHP_ROUND_HALF_UP,
        'php_round_half_down' => self::PHP_ROUND_HALF_DOWN,
        'php_round_half_even' => self::PHP_ROUND_HALF_EVEN,
        'php_round_half_odd' => self::PHP_ROUND_HALF_ODD,
        'case_lower' => self::CASE_LOWER,
        'case_upper' => self::CASE_UPPER,
        'fnm_noescape' => VmFnmatch::FNM_NOESCAPE,
        'fnm_pathname' => VmFnmatch::FNM_PATHNAME,
        'fnm_period' => VmFnmatch::FNM_PERIOD,
        'fnm_casefold' => VmFnmatch::FNM_CASEFOLD,
    ];

    /** Names exposed via get_defined_constants() Core category (fetch keys). */
    public const CORE_FETCH_NAMES = [
        'str_pad_left',
        'str_pad_right',
        'str_pad_both',
        'ent_compat',
        'ent_quotes',
        'ent_noquotes',
        'ent_ignore',
        'ent_substitute',
        'ent_disallowed',
        'ent_html401',
        'ent_xml1',
        'ent_xhtml',
        'ent_html5',
        'php_round_half_up',
        'php_round_half_down',
        'php_round_half_even',
        'php_round_half_odd',
        'case_lower',
        'case_upper',
        'fnm_noescape',
        'fnm_pathname',
        'fnm_period',
        'fnm_casefold',
    ];
}
