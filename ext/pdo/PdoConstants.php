<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

/**
 * PDO class constants (php-src ext/pdo/pdo.stub.php; #3367, #20393, #28097).
 *
 * Value tables keep legacy lowercase keys; VmPDO registration stores them on
 * ClassEntry under exact Zend casing ({@see ClassConstName::key}, #25910).
 * Display names stay in CLASS_CONSTANT_NAMES.
 */
final class PdoConstants
{
    public const PARAM_NULL = 0;

    public const PARAM_BOOL = 5;

    public const PARAM_INT = 1;

    public const PARAM_STR = 2;

    public const PARAM_LOB = 3;

    public const PARAM_STMT = 4;

    public const PARAM_INPUT_OUTPUT = 2147483648;

    public const PARAM_STR_NATL = 1073741824;

    public const PARAM_STR_CHAR = 536870912;

    public const PARAM_EVT_ALLOC = 0;

    public const PARAM_EVT_FREE = 1;

    public const PARAM_EVT_EXEC_PRE = 2;

    public const PARAM_EVT_EXEC_POST = 3;

    public const PARAM_EVT_FETCH_PRE = 4;

    public const PARAM_EVT_FETCH_POST = 5;

    public const PARAM_EVT_NORMALIZE = 6;

    public const FETCH_DEFAULT = 0;

    public const FETCH_LAZY = 1;

    public const FETCH_ASSOC = 2;

    public const FETCH_NUM = 3;

    public const FETCH_BOTH = 4;

    public const FETCH_OBJ = 5;

    public const FETCH_BOUND = 6;

    public const FETCH_COLUMN = 7;

    public const FETCH_CLASS = 8;

    public const FETCH_INTO = 9;

    public const FETCH_FUNC = 10;

    public const FETCH_GROUP = 65536;

    public const FETCH_UNIQUE = 196608;

    public const FETCH_KEY_PAIR = 12;

    public const FETCH_CLASSTYPE = 262144;

    public const FETCH_SERIALIZE = 524288;

    public const FETCH_PROPS_LATE = 1048576;

    public const FETCH_NAMED = 11;

    public const ATTR_AUTOCOMMIT = 0;

    public const ATTR_PREFETCH = 1;

    public const ATTR_TIMEOUT = 2;

    public const ATTR_ERRMODE = 3;

    public const ATTR_SERVER_VERSION = 4;

    public const ATTR_CLIENT_VERSION = 5;

    public const ATTR_SERVER_INFO = 6;

    public const ATTR_CONNECTION_STATUS = 7;

    public const ATTR_CASE = 8;

    public const ATTR_CURSOR_NAME = 9;

    public const ATTR_CURSOR = 10;

    public const ATTR_ORACLE_NULLS = 11;

    public const ATTR_PERSISTENT = 12;

    public const ATTR_STATEMENT_CLASS = 13;

    public const ATTR_FETCH_TABLE_NAMES = 14;

    public const ATTR_FETCH_CATALOG_NAMES = 15;

    public const ATTR_DRIVER_NAME = 16;

    public const ATTR_STRINGIFY_FETCHES = 17;

    public const ATTR_MAX_COLUMN_LEN = 18;

    public const ATTR_DEFAULT_FETCH_MODE = 19;

    public const ATTR_EMULATE_PREPARES = 20;

    public const ATTR_DEFAULT_STR_PARAM = 21;

    public const ERRMODE_SILENT = 0;

    public const ERRMODE_WARNING = 1;

    public const ERRMODE_EXCEPTION = 2;

    public const CASE_NATURAL = 0;

    public const CASE_LOWER = 2;

    public const CASE_UPPER = 1;

    public const NULL_NATURAL = 0;

    public const NULL_EMPTY_STRING = 1;

    public const NULL_TO_STRING = 2;

    public const ERR_NONE = '00000';

    public const FETCH_ORI_NEXT = 0;

    public const FETCH_ORI_PRIOR = 1;

    public const FETCH_ORI_FIRST = 2;

    public const FETCH_ORI_LAST = 3;

    public const FETCH_ORI_ABS = 4;

    public const FETCH_ORI_REL = 5;

    public const CURSOR_FWDONLY = 0;

    public const CURSOR_SCROLL = 1;

    /**
     * Lowercase storage key => value (int or string).
     *
     * @var array<string, int|string>
     */
    public const CLASS_CONSTANTS = [
        'param_null' => self::PARAM_NULL,
        'param_bool' => self::PARAM_BOOL,
        'param_int' => self::PARAM_INT,
        'param_str' => self::PARAM_STR,
        'param_lob' => self::PARAM_LOB,
        'param_stmt' => self::PARAM_STMT,
        'param_input_output' => self::PARAM_INPUT_OUTPUT,
        'param_str_natl' => self::PARAM_STR_NATL,
        'param_str_char' => self::PARAM_STR_CHAR,
        'param_evt_alloc' => self::PARAM_EVT_ALLOC,
        'param_evt_free' => self::PARAM_EVT_FREE,
        'param_evt_exec_pre' => self::PARAM_EVT_EXEC_PRE,
        'param_evt_exec_post' => self::PARAM_EVT_EXEC_POST,
        'param_evt_fetch_pre' => self::PARAM_EVT_FETCH_PRE,
        'param_evt_fetch_post' => self::PARAM_EVT_FETCH_POST,
        'param_evt_normalize' => self::PARAM_EVT_NORMALIZE,
        'fetch_default' => self::FETCH_DEFAULT,
        'fetch_lazy' => self::FETCH_LAZY,
        'fetch_assoc' => self::FETCH_ASSOC,
        'fetch_num' => self::FETCH_NUM,
        'fetch_both' => self::FETCH_BOTH,
        'fetch_obj' => self::FETCH_OBJ,
        'fetch_bound' => self::FETCH_BOUND,
        'fetch_column' => self::FETCH_COLUMN,
        'fetch_class' => self::FETCH_CLASS,
        'fetch_into' => self::FETCH_INTO,
        'fetch_func' => self::FETCH_FUNC,
        'fetch_group' => self::FETCH_GROUP,
        'fetch_unique' => self::FETCH_UNIQUE,
        'fetch_key_pair' => self::FETCH_KEY_PAIR,
        'fetch_classtype' => self::FETCH_CLASSTYPE,
        'fetch_serialize' => self::FETCH_SERIALIZE,
        'fetch_props_late' => self::FETCH_PROPS_LATE,
        'fetch_named' => self::FETCH_NAMED,
        'attr_autocommit' => self::ATTR_AUTOCOMMIT,
        'attr_prefetch' => self::ATTR_PREFETCH,
        'attr_timeout' => self::ATTR_TIMEOUT,
        'attr_errmode' => self::ATTR_ERRMODE,
        'attr_server_version' => self::ATTR_SERVER_VERSION,
        'attr_client_version' => self::ATTR_CLIENT_VERSION,
        'attr_server_info' => self::ATTR_SERVER_INFO,
        'attr_connection_status' => self::ATTR_CONNECTION_STATUS,
        'attr_case' => self::ATTR_CASE,
        'attr_cursor_name' => self::ATTR_CURSOR_NAME,
        'attr_cursor' => self::ATTR_CURSOR,
        'attr_oracle_nulls' => self::ATTR_ORACLE_NULLS,
        'attr_persistent' => self::ATTR_PERSISTENT,
        'attr_statement_class' => self::ATTR_STATEMENT_CLASS,
        'attr_fetch_table_names' => self::ATTR_FETCH_TABLE_NAMES,
        'attr_fetch_catalog_names' => self::ATTR_FETCH_CATALOG_NAMES,
        'attr_driver_name' => self::ATTR_DRIVER_NAME,
        'attr_stringify_fetches' => self::ATTR_STRINGIFY_FETCHES,
        'attr_max_column_len' => self::ATTR_MAX_COLUMN_LEN,
        'attr_default_fetch_mode' => self::ATTR_DEFAULT_FETCH_MODE,
        'attr_emulate_prepares' => self::ATTR_EMULATE_PREPARES,
        'attr_default_str_param' => self::ATTR_DEFAULT_STR_PARAM,
        'errmode_silent' => self::ERRMODE_SILENT,
        'errmode_warning' => self::ERRMODE_WARNING,
        'errmode_exception' => self::ERRMODE_EXCEPTION,
        'case_natural' => self::CASE_NATURAL,
        'case_lower' => self::CASE_LOWER,
        'case_upper' => self::CASE_UPPER,
        'null_natural' => self::NULL_NATURAL,
        'null_empty_string' => self::NULL_EMPTY_STRING,
        'null_to_string' => self::NULL_TO_STRING,
        'err_none' => self::ERR_NONE,
        'fetch_ori_next' => self::FETCH_ORI_NEXT,
        'fetch_ori_prior' => self::FETCH_ORI_PRIOR,
        'fetch_ori_first' => self::FETCH_ORI_FIRST,
        'fetch_ori_last' => self::FETCH_ORI_LAST,
        'fetch_ori_abs' => self::FETCH_ORI_ABS,
        'fetch_ori_rel' => self::FETCH_ORI_REL,
        'cursor_fwdonly' => self::CURSOR_FWDONLY,
        'cursor_scroll' => self::CURSOR_SCROLL,
    ];

    /** @var array<string, string> lowercase key => php-src constant casing */
    public const CLASS_CONSTANT_NAMES = [
        'param_null' => 'PARAM_NULL',
        'param_bool' => 'PARAM_BOOL',
        'param_int' => 'PARAM_INT',
        'param_str' => 'PARAM_STR',
        'param_lob' => 'PARAM_LOB',
        'param_stmt' => 'PARAM_STMT',
        'param_input_output' => 'PARAM_INPUT_OUTPUT',
        'param_str_natl' => 'PARAM_STR_NATL',
        'param_str_char' => 'PARAM_STR_CHAR',
        'param_evt_alloc' => 'PARAM_EVT_ALLOC',
        'param_evt_free' => 'PARAM_EVT_FREE',
        'param_evt_exec_pre' => 'PARAM_EVT_EXEC_PRE',
        'param_evt_exec_post' => 'PARAM_EVT_EXEC_POST',
        'param_evt_fetch_pre' => 'PARAM_EVT_FETCH_PRE',
        'param_evt_fetch_post' => 'PARAM_EVT_FETCH_POST',
        'param_evt_normalize' => 'PARAM_EVT_NORMALIZE',
        'fetch_default' => 'FETCH_DEFAULT',
        'fetch_lazy' => 'FETCH_LAZY',
        'fetch_assoc' => 'FETCH_ASSOC',
        'fetch_num' => 'FETCH_NUM',
        'fetch_both' => 'FETCH_BOTH',
        'fetch_obj' => 'FETCH_OBJ',
        'fetch_bound' => 'FETCH_BOUND',
        'fetch_column' => 'FETCH_COLUMN',
        'fetch_class' => 'FETCH_CLASS',
        'fetch_into' => 'FETCH_INTO',
        'fetch_func' => 'FETCH_FUNC',
        'fetch_group' => 'FETCH_GROUP',
        'fetch_unique' => 'FETCH_UNIQUE',
        'fetch_key_pair' => 'FETCH_KEY_PAIR',
        'fetch_classtype' => 'FETCH_CLASSTYPE',
        'fetch_serialize' => 'FETCH_SERIALIZE',
        'fetch_props_late' => 'FETCH_PROPS_LATE',
        'fetch_named' => 'FETCH_NAMED',
        'attr_autocommit' => 'ATTR_AUTOCOMMIT',
        'attr_prefetch' => 'ATTR_PREFETCH',
        'attr_timeout' => 'ATTR_TIMEOUT',
        'attr_errmode' => 'ATTR_ERRMODE',
        'attr_server_version' => 'ATTR_SERVER_VERSION',
        'attr_client_version' => 'ATTR_CLIENT_VERSION',
        'attr_server_info' => 'ATTR_SERVER_INFO',
        'attr_connection_status' => 'ATTR_CONNECTION_STATUS',
        'attr_case' => 'ATTR_CASE',
        'attr_cursor_name' => 'ATTR_CURSOR_NAME',
        'attr_cursor' => 'ATTR_CURSOR',
        'attr_oracle_nulls' => 'ATTR_ORACLE_NULLS',
        'attr_persistent' => 'ATTR_PERSISTENT',
        'attr_statement_class' => 'ATTR_STATEMENT_CLASS',
        'attr_fetch_table_names' => 'ATTR_FETCH_TABLE_NAMES',
        'attr_fetch_catalog_names' => 'ATTR_FETCH_CATALOG_NAMES',
        'attr_driver_name' => 'ATTR_DRIVER_NAME',
        'attr_stringify_fetches' => 'ATTR_STRINGIFY_FETCHES',
        'attr_max_column_len' => 'ATTR_MAX_COLUMN_LEN',
        'attr_default_fetch_mode' => 'ATTR_DEFAULT_FETCH_MODE',
        'attr_emulate_prepares' => 'ATTR_EMULATE_PREPARES',
        'attr_default_str_param' => 'ATTR_DEFAULT_STR_PARAM',
        'errmode_silent' => 'ERRMODE_SILENT',
        'errmode_warning' => 'ERRMODE_WARNING',
        'errmode_exception' => 'ERRMODE_EXCEPTION',
        'case_natural' => 'CASE_NATURAL',
        'case_lower' => 'CASE_LOWER',
        'case_upper' => 'CASE_UPPER',
        'null_natural' => 'NULL_NATURAL',
        'null_empty_string' => 'NULL_EMPTY_STRING',
        'null_to_string' => 'NULL_TO_STRING',
        'err_none' => 'ERR_NONE',
        'fetch_ori_next' => 'FETCH_ORI_NEXT',
        'fetch_ori_prior' => 'FETCH_ORI_PRIOR',
        'fetch_ori_first' => 'FETCH_ORI_FIRST',
        'fetch_ori_last' => 'FETCH_ORI_LAST',
        'fetch_ori_abs' => 'FETCH_ORI_ABS',
        'fetch_ori_rel' => 'FETCH_ORI_REL',
        'cursor_fwdonly' => 'CURSOR_FWDONLY',
        'cursor_scroll' => 'CURSOR_SCROLL',
    ];
}
