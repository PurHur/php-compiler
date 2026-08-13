--TEST--
Language: settype(resource, object) — stdClass.scalar (#30793, ext/standard/type.c)
--FILE--
<?php
$v = fopen('php://memory', 'r');
settype($v, 'object');
echo get_debug_type($v), "\n";
echo isset($v->scalar) ? "has_scalar\n" : "no_scalar\n";
echo get_debug_type($v->scalar), "\n";
?>
--EXPECT--
stdClass
has_scalar
resource (stream)
