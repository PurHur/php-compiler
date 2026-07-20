--TEST--
ext/odbc odbc_setoption builtin registered (#21267)
--FILE--
<?php
echo 'odbc_setoption=', var_export(function_exists('odbc_setoption'), true), "\n";
?>
--EXPECT--
odbc_setoption=true
