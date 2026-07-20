<?php
/** Issue #21256 — odbc_connection_string_* curly-brace helpers. */
foreach ([
    'odbc_connection_string_is_quoted',
    'odbc_connection_string_should_quote',
    'odbc_connection_string_quote',
] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'MISSING', "\n";
}
echo 'is_quoted={abc}=', var_export(odbc_connection_string_is_quoted('{abc}'), true), "\n";
echo 'should_quote=a;b=', var_export(odbc_connection_string_should_quote('a;b'), true), "\n";
echo 'quote=a;b=', var_export(odbc_connection_string_quote('a;b'), true), "\n";
echo 'quote=foo}bar=', var_export(odbc_connection_string_quote('foo}bar'), true), "\n";
