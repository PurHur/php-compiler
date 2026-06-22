--TEST--
stdlib nested scalar-return builtin in call argument (#10495, Zend/zend_execute.c)
--FILE--
<?php
declare(strict_types=1);

echo var_export(get_debug_type(null), true), "\n";
echo var_export(gettype(null), true), "\n";
echo var_export(json_encode(null), true), "\n";
echo var_export(get_class(new stdClass()), true), "\n";
--EXPECT--
'null'
'NULL'
'null'
'stdClass'
