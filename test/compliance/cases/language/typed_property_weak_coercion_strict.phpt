--TEST--
Language: typed property strict-mode int coercion rejects float/string (#12347, Zend/zend_types.c)
--FILE--
<?php
declare(strict_types=1);

class StrictIntProp
{
    public int $p;
}

$s = new StrictIntProp();
try {
    $s->p = 1.5;
    echo "strict_float_ok\n";
} catch (TypeError $e) {
    echo "strict_float_TypeError\n";
}
try {
    $s->p = '42.0';
    echo "strict_str_ok\n";
} catch (TypeError $e) {
    echo "strict_str_TypeError\n";
}
--EXPECT--
strict_float_TypeError
strict_str_TypeError
