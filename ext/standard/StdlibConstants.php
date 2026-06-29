<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Zend predefined constants for ext/standard (php-src basic_functions.c PHP_MINIT).
 *
 * @see ext/standard/basic_functions.c — STR_PAD_*, ENT_*, PHP_ROUND_*, M_*
 * @see ext/standard/php_array.h — SORT_*, EXTR_*
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

    /** get_html_translation_table() table selector (ext/standard/html.c). */
    public const HTML_SPECIALCHARS = 0;
    public const HTML_ENTITIES = 1;

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

    /** round() mode flags (ext/standard/php_math_round_mode.h). */
    public const PHP_ROUND_HALF_UP = 1;
    public const PHP_ROUND_HALF_DOWN = 2;
    public const PHP_ROUND_HALF_EVEN = 3;
    public const PHP_ROUND_HALF_ODD = 4;
    public const PHP_ROUND_CEILING = 5;
    public const PHP_ROUND_FLOOR = 6;
    public const PHP_ROUND_TOWARD_ZERO = 7;
    public const PHP_ROUND_AWAY_FROM_ZERO = 8;

    /** array_change_key_case() mode flags (ext/standard/array.c). */
    public const CASE_LOWER = 0;
    public const CASE_UPPER = 1;

    /** parse_ini_*() scanner modes (ext/standard/ini.c). */
    public const INI_SCANNER_NORMAL = 0;
    public const INI_SCANNER_RAW = 1;
    public const INI_SCANNER_TYPED = 2;

    /** file() flags (ext/standard/file.c; PHP bitmask constants). */
    public const FILE_USE_INCLUDE_PATH = 1;
    public const FILE_IGNORE_NEW_LINES = 2;
    public const FILE_SKIP_EMPTY_LINES = 4;
    public const FILE_APPEND = 8;

    /** sort() / array_multisort() flags (ext/standard/php_array.h). */
    public const SORT_REGULAR = 0;
    public const SORT_NUMERIC = 1;
    public const SORT_STRING = 2;
    public const SORT_DESC = 3;
    public const SORT_ASC = 4;
    public const SORT_LOCALE_STRING = 5;
    public const SORT_NATURAL = 6;
    public const SORT_FLAG_CASE = 8;

    /** array_filter() mode flags (ext/standard/array.c). */
    public const ARRAY_FILTER_USE_BOTH = 1;
    public const ARRAY_FILTER_USE_KEY = 2;

    /** extract() flags (ext/standard/php_array.h). */
    public const EXTR_OVERWRITE = 0;
    public const EXTR_SKIP = 1;
    public const EXTR_PREFIX_SAME = 2;
    public const EXTR_PREFIX_ALL = 3;
    public const EXTR_PREFIX_INVALID = 4;
    public const EXTR_PREFIX_IF_EXISTS = 5;
    public const EXTR_IF_EXISTS = 6;
    public const EXTR_REFS = 0x100;

    /** pathinfo() component flags (ext/standard/basic_functions.c). */
    public const PATHINFO_DIRNAME = 1;
    public const PATHINFO_BASENAME = 2;
    public const PATHINFO_EXTENSION = 4;
    public const PATHINFO_FILENAME = 8;
    public const PATHINFO_ALL = 15;

    /** preg_split() flags (ext/pcre/php_pcre.c). */
    public const PREG_SPLIT_NO_EMPTY = 1;
    public const PREG_SPLIT_DELIM_CAPTURE = 2;
    public const PREG_SPLIT_OFFSET_CAPTURE = 4;

    /** preg_grep() flags (ext/pcre/php_pcre.c). */
    public const PREG_GREP_INVERT = 1;

    /** preg_match() / preg_match_all() flags (ext/pcre/php_pcre.c). */
    public const PREG_PATTERN_ORDER = 1;
    public const PREG_SET_ORDER = 2;
    public const PREG_OFFSET_CAPTURE = 256;
    public const PREG_UNMATCHED_AS_NULL = 512;

    /** preg_last_error() codes (ext/pcre/php_pcre.h). */
    public const PREG_NO_ERROR = 0;
    public const PREG_INTERNAL_ERROR = 1;
    public const PREG_BACKTRACK_LIMIT_ERROR = 2;
    public const PREG_RECURSION_LIMIT_ERROR = 3;
    public const PREG_BAD_UTF8_ERROR = 4;
    public const PREG_BAD_UTF8_OFFSET_ERROR = 5;
    public const PREG_JIT_STACKLIMIT_ERROR = 6;

    /** http_build_query() encoding_type (ext/standard/http.c, main/php_variables.h). */
    public const PHP_QUERY_RFC1738 = VmHttpBuildQuery::ENCODING_RFC1738;
    public const PHP_QUERY_RFC3986 = VmHttpBuildQuery::ENCODING_RFC3986;

    /** password_hash() algorithms — user-visible string ids (ext/standard/password.c). */
    public const PASSWORD_BCRYPT = '2y';

    public const PASSWORD_DEFAULT = VmPassword::PASSWORD_DEFAULT;

    public const PASSWORD_ARGON2I = 'argon2i';

    public const PASSWORD_ARGON2ID = 'argon2id';

    /** assert_options() selectors (ext/standard/assert.c). */
    public const ASSERT_ACTIVE = 1;
    public const ASSERT_CALLBACK = 2;
    public const ASSERT_BAIL = 3;
    public const ASSERT_WARNING = 4;
    public const ASSERT_EXCEPTION = 5;

    /** stream_filter_append/prepend $read_write (ext/standard/streams.c, #3283). */
    public const STREAM_FILTER_READ = 1;

    public const STREAM_FILTER_WRITE = 2;

    /** php_user_filter::filter() status (ext/standard/php_stream_filter.h; #11747). */
    public const PSFS_PASS_ON = 2;

    public const PSFS_FEED_ME = 1;

    public const PSFS_FLAG_NORMAL = 0;

    public const PSFS_FLAG_FLUSH_INC = 1;

    public const PSFS_FLAG_FLUSH_CLOSE = 2;

    /** stream_socket_client() / stream_socket_server() flags (ext/standard/streamsfuncs.c, #4993). */
    public const STREAM_CLIENT_PERSISTENT = 1;

    public const STREAM_CLIENT_ASYNC_CONNECT = 2;

    public const STREAM_CLIENT_CONNECT = 4;

    /** stream_socket_client() error reporting (main/streams/php_stream_wrappers.h). */
    public const STREAM_REPORT_ERRORS = 8;

    public const STREAM_SERVER_BIND = 4;

    public const STREAM_SERVER_LISTEN = 8;

    /** stream_socket_pair() domain/type/protocol (ext/standard/streams.c, #3437). */
    public const STREAM_PF_UNIX = 1;

    public const STREAM_PF_INET = 2;

    public const STREAM_SOCK_STREAM = 1;

    public const STREAM_SOCK_DGRAM = 2;

    public const STREAM_IPPROTO_IP = 0;

    /** stream_cast() cast modes (ext/standard/streams.c PHP_MINIT). */
    public const STREAM_CAST_AS_STREAM = 0;

    public const STREAM_CAST_FOR_SELECT = 3;

    /** syslog priorities (syslog.h; ext/standard/basic_functions.c). */
    public const LOG_EMERG = 0;
    public const LOG_ALERT = 1;
    public const LOG_CRIT = 2;
    public const LOG_ERR = 3;
    public const LOG_WARNING = 4;
    public const LOG_NOTICE = 5;
    public const LOG_INFO = 6;
    public const LOG_DEBUG = 7;

    /** openlog() option flags (syslog.h). */
    public const LOG_PID = 1;
    public const LOG_CONS = 2;
    public const LOG_ODELAY = 4;
    public const LOG_NDELAY = 8;
    public const LOG_NOWAIT = 16;
    public const LOG_PERROR = 32;

    /** openlog() facility codes (syslog.h; Linux values). */
    public const LOG_KERN = 0;
    public const LOG_USER = 8;
    public const LOG_MAIL = 16;
    public const LOG_DAEMON = 24;
    public const LOG_AUTH = 32;
    public const LOG_SYSLOG = 40;
    public const LOG_LPR = 48;
    public const LOG_NEWS = 56;
    public const LOG_UUCP = 64;
    public const LOG_CRON = 72;
    public const LOG_AUTHPRIV = 80;
    public const LOG_FTP = 88;
    public const LOG_LOCAL0 = 128;
    public const LOG_LOCAL1 = 136;
    public const LOG_LOCAL2 = 144;
    public const LOG_LOCAL3 = 152;
    public const LOG_LOCAL4 = 160;
    public const LOG_LOCAL5 = 168;
    public const LOG_LOCAL6 = 176;
    public const LOG_LOCAL7 = 184;

    /** dns_get_record() type bitmasks (ext/standard/dns.c). */
    public const DNS_A = 1;
    public const DNS_NS = 2;
    public const DNS_CNAME = 4;
    public const DNS_SOA = 8;
    public const DNS_PTR = 16;
    public const DNS_HINFO = 32;
    public const DNS_MX = 64;
    public const DNS_TXT = 128;
    public const DNS_AAAA = 256;
    public const DNS_SRV = 512;
    public const DNS_NAPTR = 1024;
    public const DNS_A6 = 2048;
    public const DNS_ALL = 4095;
    public const DNS_ANY = 4096;

    /** glob() flags (ext/standard/dir.c / glob.h; values match php-src on Linux). */
    public const GLOB_ERR = 1;
    public const GLOB_MARK = 2;
    public const GLOB_NOSORT = 4;
    public const GLOB_NOCHECK = 16;
    public const GLOB_NOESCAPE = 64;
    public const GLOB_BRACE = 1024;
    public const GLOB_ONLYDIR = 8192;
    public const GLOB_AVAILABLE_FLAGS = 9303;

    /** Lowercase name => int value for VM\Context::constantFetch(). */
    public const CORE_INT_BY_NAME = [
        'str_pad_left' => self::STR_PAD_LEFT,
        'str_pad_right' => self::STR_PAD_RIGHT,
        'str_pad_both' => self::STR_PAD_BOTH,
        'html_specialchars' => self::HTML_SPECIALCHARS,
        'html_entities' => self::HTML_ENTITIES,
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
        'php_round_ceiling' => self::PHP_ROUND_CEILING,
        'php_round_floor' => self::PHP_ROUND_FLOOR,
        'php_round_toward_zero' => self::PHP_ROUND_TOWARD_ZERO,
        'php_round_away_from_zero' => self::PHP_ROUND_AWAY_FROM_ZERO,
        'case_lower' => self::CASE_LOWER,
        'case_upper' => self::CASE_UPPER,
        'ini_scanner_normal' => self::INI_SCANNER_NORMAL,
        'ini_scanner_raw' => self::INI_SCANNER_RAW,
        'ini_scanner_typed' => self::INI_SCANNER_TYPED,
        'file_ignore_new_lines' => self::FILE_IGNORE_NEW_LINES,
        'file_skip_empty_lines' => self::FILE_SKIP_EMPTY_LINES,
        'file_use_include_path' => self::FILE_USE_INCLUDE_PATH,
        'file_append' => self::FILE_APPEND,
        'pathinfo_dirname' => self::PATHINFO_DIRNAME,
        'pathinfo_basename' => self::PATHINFO_BASENAME,
        'pathinfo_extension' => self::PATHINFO_EXTENSION,
        'pathinfo_filename' => self::PATHINFO_FILENAME,
        'pathinfo_all' => self::PATHINFO_ALL,
        'fnm_noescape' => VmFnmatch::FNM_NOESCAPE,
        'fnm_pathname' => VmFnmatch::FNM_PATHNAME,
        'fnm_period' => VmFnmatch::FNM_PERIOD,
        'fnm_casefold' => VmFnmatch::FNM_CASEFOLD,
        'stream_filter_read' => self::STREAM_FILTER_READ,
        'stream_filter_write' => self::STREAM_FILTER_WRITE,
        'psfs_pass_on' => self::PSFS_PASS_ON,
        'psfs_feed_me' => self::PSFS_FEED_ME,
        'psfs_flag_normal' => self::PSFS_FLAG_NORMAL,
        'psfs_flag_flush_inc' => self::PSFS_FLAG_FLUSH_INC,
        'psfs_flag_flush_close' => self::PSFS_FLAG_FLUSH_CLOSE,
        'stream_client_persistent' => self::STREAM_CLIENT_PERSISTENT,
        'stream_client_async_connect' => self::STREAM_CLIENT_ASYNC_CONNECT,
        'stream_client_connect' => self::STREAM_CLIENT_CONNECT,
        'stream_report_errors' => self::STREAM_REPORT_ERRORS,
        'stream_server_bind' => self::STREAM_SERVER_BIND,
        'stream_server_listen' => self::STREAM_SERVER_LISTEN,
        'stream_pf_unix' => self::STREAM_PF_UNIX,
        'stream_pf_inet' => self::STREAM_PF_INET,
        'stream_sock_stream' => self::STREAM_SOCK_STREAM,
        'stream_sock_dgram' => self::STREAM_SOCK_DGRAM,
        'stream_iproto_ip' => self::STREAM_IPPROTO_IP,
        'stream_cast_as_stream' => self::STREAM_CAST_AS_STREAM,
        'stream_cast_for_select' => self::STREAM_CAST_FOR_SELECT,
        'sunfuncs_ret_string' => VmDate::SUNFUNCS_RET_STRING,
        'sunfuncs_ret_double' => VmDate::SUNFUNCS_RET_DOUBLE,
        'sunfuncs_ret_timestamp' => VmDate::SUNFUNCS_RET_TIMESTAMP,
        'count_normal' => VmArray::COUNT_NORMAL,
        'count_recursive' => VmArray::COUNT_RECURSIVE,
        'sort_regular' => self::SORT_REGULAR,
        'sort_numeric' => self::SORT_NUMERIC,
        'sort_string' => self::SORT_STRING,
        'sort_desc' => self::SORT_DESC,
        'sort_asc' => self::SORT_ASC,
        'sort_locale_string' => self::SORT_LOCALE_STRING,
        'sort_natural' => self::SORT_NATURAL,
        'sort_flag_case' => self::SORT_FLAG_CASE,
        'array_filter_use_both' => self::ARRAY_FILTER_USE_BOTH,
        'array_filter_use_key' => self::ARRAY_FILTER_USE_KEY,
        'extr_overwrite' => self::EXTR_OVERWRITE,
        'extr_skip' => self::EXTR_SKIP,
        'extr_prefix_same' => self::EXTR_PREFIX_SAME,
        'extr_prefix_all' => self::EXTR_PREFIX_ALL,
        'extr_prefix_invalid' => self::EXTR_PREFIX_INVALID,
        'extr_prefix_if_exists' => self::EXTR_PREFIX_IF_EXISTS,
        'extr_if_exists' => self::EXTR_IF_EXISTS,
        'extr_refs' => self::EXTR_REFS,
        'preg_split_no_empty' => self::PREG_SPLIT_NO_EMPTY,
        'preg_split_delim_capture' => self::PREG_SPLIT_DELIM_CAPTURE,
        'preg_split_offset_capture' => self::PREG_SPLIT_OFFSET_CAPTURE,
        'preg_grep_invert' => self::PREG_GREP_INVERT,
        'preg_pattern_order' => self::PREG_PATTERN_ORDER,
        'preg_set_order' => self::PREG_SET_ORDER,
        'preg_offset_capture' => self::PREG_OFFSET_CAPTURE,
        'preg_unmatched_as_null' => self::PREG_UNMATCHED_AS_NULL,
        'preg_no_error' => self::PREG_NO_ERROR,
        'preg_internal_error' => self::PREG_INTERNAL_ERROR,
        'preg_backtrack_limit_error' => self::PREG_BACKTRACK_LIMIT_ERROR,
        'preg_recursion_limit_error' => self::PREG_RECURSION_LIMIT_ERROR,
        'preg_bad_utf8_error' => self::PREG_BAD_UTF8_ERROR,
        'preg_bad_utf8_offset_error' => self::PREG_BAD_UTF8_OFFSET_ERROR,
        'preg_jit_stacklimit_error' => self::PREG_JIT_STACKLIMIT_ERROR,
        'php_query_rfc1738' => self::PHP_QUERY_RFC1738,
        'php_query_rfc3986' => self::PHP_QUERY_RFC3986,
        'log_emerg' => self::LOG_EMERG,
        'log_alert' => self::LOG_ALERT,
        'log_crit' => self::LOG_CRIT,
        'log_err' => self::LOG_ERR,
        'log_warning' => self::LOG_WARNING,
        'log_notice' => self::LOG_NOTICE,
        'log_info' => self::LOG_INFO,
        'log_debug' => self::LOG_DEBUG,
        'log_pid' => self::LOG_PID,
        'log_cons' => self::LOG_CONS,
        'log_odelay' => self::LOG_ODELAY,
        'log_ndelay' => self::LOG_NDELAY,
        'log_nowait' => self::LOG_NOWAIT,
        'log_perror' => self::LOG_PERROR,
        'log_kern' => self::LOG_KERN,
        'log_user' => self::LOG_USER,
        'log_mail' => self::LOG_MAIL,
        'log_daemon' => self::LOG_DAEMON,
        'log_auth' => self::LOG_AUTH,
        'log_syslog' => self::LOG_SYSLOG,
        'log_lpr' => self::LOG_LPR,
        'log_news' => self::LOG_NEWS,
        'log_uucp' => self::LOG_UUCP,
        'log_cron' => self::LOG_CRON,
        'log_authpriv' => self::LOG_AUTHPRIV,
        'log_ftp' => self::LOG_FTP,
        'log_local0' => self::LOG_LOCAL0,
        'log_local1' => self::LOG_LOCAL1,
        'log_local2' => self::LOG_LOCAL2,
        'log_local3' => self::LOG_LOCAL3,
        'log_local4' => self::LOG_LOCAL4,
        'log_local5' => self::LOG_LOCAL5,
        'log_local6' => self::LOG_LOCAL6,
        'log_local7' => self::LOG_LOCAL7,
        'glob_err' => self::GLOB_ERR,
        'glob_mark' => self::GLOB_MARK,
        'glob_nosort' => self::GLOB_NOSORT,
        'glob_nocheck' => self::GLOB_NOCHECK,
        'glob_noescape' => self::GLOB_NOESCAPE,
        'glob_brace' => self::GLOB_BRACE,
        'glob_onlydir' => self::GLOB_ONLYDIR,
        'glob_available_flags' => self::GLOB_AVAILABLE_FLAGS,
        'dns_a' => self::DNS_A,
        'dns_ns' => self::DNS_NS,
        'dns_cname' => self::DNS_CNAME,
        'dns_soa' => self::DNS_SOA,
        'dns_ptr' => self::DNS_PTR,
        'dns_hinfo' => self::DNS_HINFO,
        'dns_mx' => self::DNS_MX,
        'dns_txt' => self::DNS_TXT,
        'dns_aaaa' => self::DNS_AAAA,
        'dns_srv' => self::DNS_SRV,
        'dns_naptr' => self::DNS_NAPTR,
        'dns_a6' => self::DNS_A6,
        'dns_all' => self::DNS_ALL,
        'dns_any' => self::DNS_ANY,
        'assert_active' => self::ASSERT_ACTIVE,
        'assert_callback' => self::ASSERT_CALLBACK,
        'assert_bail' => self::ASSERT_BAIL,
        'assert_warning' => self::ASSERT_WARNING,
        'assert_exception' => self::ASSERT_EXCEPTION,
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
        'html_specialchars',
        'html_entities',
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
        'php_round_ceiling',
        'php_round_floor',
        'php_round_toward_zero',
        'php_round_away_from_zero',
        'case_lower',
        'case_upper',
        'ini_scanner_normal',
        'ini_scanner_raw',
        'ini_scanner_typed',
        'file_ignore_new_lines',
        'file_skip_empty_lines',
        'file_use_include_path',
        'file_append',
        'pathinfo_dirname',
        'pathinfo_basename',
        'pathinfo_extension',
        'pathinfo_filename',
        'pathinfo_all',
        'fnm_noescape',
        'fnm_pathname',
        'fnm_period',
        'fnm_casefold',
        'sunfuncs_ret_string',
        'sunfuncs_ret_double',
        'sunfuncs_ret_timestamp',
        'count_normal',
        'count_recursive',
        'sort_regular',
        'sort_numeric',
        'sort_string',
        'sort_desc',
        'sort_asc',
        'sort_locale_string',
        'sort_natural',
        'sort_flag_case',
        'array_filter_use_both',
        'array_filter_use_key',
        'extr_overwrite',
        'extr_skip',
        'extr_prefix_same',
        'extr_prefix_all',
        'extr_prefix_invalid',
        'extr_prefix_if_exists',
        'extr_if_exists',
        'extr_refs',
        'preg_split_no_empty',
        'preg_split_delim_capture',
        'preg_split_offset_capture',
        'preg_grep_invert',
        'log_emerg',
        'log_alert',
        'log_crit',
        'log_err',
        'log_warning',
        'log_notice',
        'log_info',
        'log_debug',
        'log_pid',
        'log_cons',
        'log_odelay',
        'log_ndelay',
        'log_nowait',
        'log_perror',
        'log_kern',
        'log_user',
        'log_mail',
        'log_daemon',
        'log_auth',
        'log_syslog',
        'log_lpr',
        'log_news',
        'log_uucp',
        'log_cron',
        'log_authpriv',
        'log_ftp',
        'log_local0',
        'log_local1',
        'log_local2',
        'log_local3',
        'log_local4',
        'log_local5',
        'log_local6',
        'log_local7',
        'glob_err',
        'glob_mark',
        'glob_nosort',
        'glob_nocheck',
        'glob_noescape',
        'glob_brace',
        'glob_onlydir',
        'glob_available_flags',
        'password_bcrypt',
        'password_default',
        'password_argon2i',
        'password_argon2id',
        'assert_active',
        'assert_callback',
        'assert_bail',
        'assert_warning',
        'assert_exception',
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
