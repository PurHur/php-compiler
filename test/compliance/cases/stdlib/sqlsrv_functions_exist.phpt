--TEST--
ext/sqlsrv Phase 1 builtins registered; invalid connect returns false + sqlsrv_errors (#6577)
--FILE--
<?php
foreach ([
    'sqlsrv_connect',
    'sqlsrv_query',
    'sqlsrv_fetch_array',
    'sqlsrv_errors',
    'sqlsrv_close',
] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
echo 'extension_loaded=', var_export(extension_loaded('sqlsrv'), true), "\n";

$conn = sqlsrv_connect('localhost', ['Database' => 'test', 'UID' => 'sa', 'PWD' => 'x']);
echo 'connect=', var_export($conn, true), "\n";
$errors = sqlsrv_errors();
echo 'errors_is_array=', var_export(is_array($errors), true), "\n";
echo 'errors_count=', count($errors), "\n";
if (is_array($errors) && isset($errors[0]['SQLSTATE'])) {
    echo 'sqlstate=', $errors[0]['SQLSTATE'], "\n";
    echo 'code=', $errors[0]['code'], "\n";
}
?>
--EXPECT--
sqlsrv_connect=true
sqlsrv_query=true
sqlsrv_fetch_array=true
sqlsrv_errors=true
sqlsrv_close=true
extension_loaded=true
connect=false
errors_is_array=true
errors_count=1
sqlstate=IMSSP
code=-49
