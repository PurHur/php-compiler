--TEST--
AOT: (object) cast on stream resource — stdClass.scalar (#30793, Zend/zend_operators.c)
--FILE--
<?php
$f = fopen('php://memory', 'r');
$o = (object) $f;
echo get_class($o), "\n";
echo isset($o->scalar) ? "has_scalar\n" : "no_scalar\n";
echo is_resource($o->scalar) ? "open\n" : "not_open\n";
echo is_object($o->scalar) ? "obj\n" : "noobj\n";
?>
--EXPECT--
stdClass
has_scalar
open
noobj
