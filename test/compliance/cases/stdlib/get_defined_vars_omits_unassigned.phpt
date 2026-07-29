--TEST--
stdlib get_defined_vars() omits unassigned compile-time locals (#24660, zend_get_defined_vars)
--FILE--
<?php
declare(strict_types=1);

$foo = 1;
$keys = array_keys(get_defined_vars());
sort($keys);
echo implode(',', $keys), "\n";
$bar = null;
$keys2 = array_keys(get_defined_vars());
sort($keys2);
echo implode(',', $keys2), "\n";
--EXPECT--
_COOKIE,_FILES,_GET,_POST,_SERVER,argc,argv,foo
_COOKIE,_FILES,_GET,_POST,_SERVER,argc,argv,bar,foo,keys
