--TEST--
stdlib settype() enum case to string JIT — Error then operand coerces to '' (#16043, ext/standard/type.c)
--JIT--
--FILE--
<?php
enum E
{
    case A;
}

$v = E::A;
try {
    settype($v, 'string');
} catch (Error $e) {
    echo 'Error';
}
var_export($v);
?>
--EXPECT--
Error''
