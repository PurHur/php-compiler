--TEST--
Language: (object) cast on stream resource — stdClass.scalar (#30793, Zend/zend_operators.c)
--FILE--
<?php
$f = fopen('php://memory', 'r');
$o = (object) $f;
echo get_debug_type($o), "\n";
echo isset($o->scalar) ? "has_scalar\n" : "no_scalar\n";
echo get_debug_type($o->scalar), "\n";
echo is_resource($o->scalar) ? "open\n" : "not_open\n";
fclose($f);
$closed = (object) $f;
echo get_debug_type($closed), "\n";
echo isset($closed->scalar) ? "has_scalar\n" : "no_scalar\n";
echo get_debug_type($closed->scalar), "\n";
?>
--EXPECT--
stdClass
has_scalar
resource (stream)
open
stdClass
has_scalar
resource (closed)
