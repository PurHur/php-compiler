--TEST--
Language settype() on backed enum case to string JIT — Error not backing coercion (#8861, ext/standard/type.c)
--JIT--
--FILE--
<?php
enum Es: string
{
    case A = 'a';
}

$v = Es::A;
try {
    settype($v, 'string');
    echo "settype ok: ", var_export($v, true), "\n";
} catch (Throwable $e) {
    echo 'settype ', $e::class, ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
settype Error: Object of class Es could not be converted to string
