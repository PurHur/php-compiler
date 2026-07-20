<?php
/** Issue #21258 — odbc prepare/fetch/tables surface registration. */
foreach ([
    'odbc_prepare',
    'odbc_execute',
    'odbc_fetch_array',
    'odbc_fetch_object',
    'odbc_fetch_into',
    'odbc_tables',
    'odbc_columns',
    'odbc_num_fields',
    'odbc_field_name',
    'odbc_field_type',
    'odbc_field_len',
    'odbc_field_num',
    'odbc_free_result',
] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'MISSING', "\n";
}
