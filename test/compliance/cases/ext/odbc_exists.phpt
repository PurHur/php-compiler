--TEST--
ext/odbc Phase 1 builtins registered; function_exists true (#6293)
--FILE--
<?php
foreach ([
    'odbc_connect',
    'odbc_pconnect',
    'odbc_close',
    'odbc_exec',
    'odbc_fetch_row',
    'odbc_result',
    'odbc_num_rows',
    'odbc_error',
    'odbc_errormsg',
] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
echo 'extension_loaded=', var_export(extension_loaded('odbc'), true), "\n";
?>
--EXPECT--
odbc_connect=true
odbc_pconnect=true
odbc_close=true
odbc_exec=true
odbc_fetch_row=true
odbc_result=true
odbc_num_rows=true
odbc_error=true
odbc_errormsg=true
extension_loaded=true
