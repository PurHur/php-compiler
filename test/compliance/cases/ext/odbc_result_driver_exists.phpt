--TEST--
ext/odbc next_result/data_source/binmode/longreadlen registered (#21278)
--ENV--
PHP_COMPILER_ENABLE_ODBC=1
--FILE--
<?php
foreach ([
    'odbc_next_result',
    'odbc_data_source',
    'odbc_binmode',
    'odbc_longreadlen',
] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
echo 'SQL_FETCH_FIRST=', (int) SQL_FETCH_FIRST, "\n";
echo 'SQL_FETCH_NEXT=', (int) SQL_FETCH_NEXT, "\n";
?>
--EXPECT--
odbc_next_result=true
odbc_data_source=true
odbc_binmode=true
odbc_longreadlen=true
SQL_FETCH_FIRST=2
SQL_FETCH_NEXT=1
