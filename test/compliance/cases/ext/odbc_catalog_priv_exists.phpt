--TEST--
ext/odbc tableprivileges/columnprivileges registered (#21295)
--FILE--
<?php
foreach ([
    'odbc_tableprivileges',
    'odbc_columnprivileges',
] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
?>
--EXPECT--
odbc_tableprivileges=true
odbc_columnprivileges=true
