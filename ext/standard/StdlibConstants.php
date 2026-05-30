<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Zend predefined constants for ext/standard (php-src basic_functions.c PHP_MINIT).
 *
 * @see ext/standard/basic_functions.c — STR_PAD_*, ENT_*, PHP_ROUND_*, M_*
 * @see ext/standard/php_math.h — M_PI, M_E, …
 */
final class StdlibConstants
{
    /** math.h M_* constants (ext/standard/php_math.h). */
    public const M_E = 2.7182818284590452354;
    public const M_LOG2E = 1.4426950408889634074;
    public const M_LOG10E = 0.43429448190325182765;
    public const M_LN2 = 0.69314718055994530942;
    public const M_LN10 = 2.30258509299404568402;
    public const M_PI = 3.14159265358979323846;
    public const M_PI_2 = 1.57079632679489661923;
    public const M_PI_4 = 0.78539816339744830962;
    public const M_1_PI = 0.31830988618379067154;
    public const M_2_PI = 0.63661977236758134308;
    public const M_SQRTPI = 1.77245385090551602729;
    public const M_2_SQRTPI = 1.12837916709551257390;
    public const M_LNPI = 1.14472988584940017414;
    public const M_EULER = 0.57721566490153286061;
    public const M_SQRT2 = 1.41421356237309504880;
    public const M_SQRT1_2 = 0.70710678118654752440;
    public const M_SQRT3 = 1.73205080756887729352;
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
        'count_normal' => VmArray::COUNT_NORMAL,
        'count_recursive' => VmArray::COUNT_RECURSIVE,
    ];

    /** Lowercase name => float value for VM\Context::constantFetch(). */
    public const CORE_FLOAT_BY_NAME = [
        'm_e' => self::M_E,
        'm_log2e' => self::M_LOG2E,
        'm_log10e' => self::M_LOG10E,
        'm_ln2' => self::M_LN2,
        'm_ln10' => self::M_LN10,
        'm_pi' => self::M_PI,
        'm_pi_2' => self::M_PI_2,
        'm_pi_4' => self::M_PI_4,
        'm_1_pi' => self::M_1_PI,
        'm_2_pi' => self::M_2_PI,
        'm_sqrtpi' => self::M_SQRTPI,
        'm_2_sqrtpi' => self::M_2_SQRTPI,
        'm_lnpi' => self::M_LNPI,
        'm_euler' => self::M_EULER,
        'm_sqrt2' => self::M_SQRT2,
        'm_sqrt1_2' => self::M_SQRT1_2,
        'm_sqrt3' => self::M_SQRT3,
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
        'count_normal',
        'count_recursive',
        'm_e',
        'm_log2e',
        'm_log10e',
        'm_ln2',
        'm_ln10',
        'm_pi',
        'm_pi_2',
        'm_pi_4',
        'm_1_pi',
        'm_2_pi',
        'm_sqrtpi',
        'm_2_sqrtpi',
        'm_lnpi',
        'm_euler',
        'm_sqrt2',
        'm_sqrt1_2',
        'm_sqrt3',
    ];
}
