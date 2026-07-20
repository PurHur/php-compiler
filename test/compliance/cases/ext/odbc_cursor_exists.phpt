--TEST--
ext/odbc odbc_cursor registered (#21307)
--FILE--
<?php
echo 'odbc_cursor=', var_export(function_exists('odbc_cursor'), true), "\n";
?>
--EXPECT--
odbc_cursor=true
