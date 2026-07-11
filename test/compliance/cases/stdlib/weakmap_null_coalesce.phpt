--TEST--
stdlib WeakMap offset ?? null — present entry keeps value (issue #11601, Zend/zend_weakrefs.c)
--FILE--
<?php
declare(strict_types=1);
$wm = new WeakMap();
$obj = new stdClass();
$wm[$obj] = 'val';
echo var_export($wm[$obj] ?? null, true), "\n";
--EXPECT--
'val'
