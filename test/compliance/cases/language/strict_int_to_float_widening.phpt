--TEST--
Language: declare(strict_types=1) allows int→float widening (params/return/property) (#28615, Zend/zend_execute.h)
--FILE--
<?php
declare(strict_types=1);

function takes_float(float $x): float
{
    return $x;
}

function returns_float(int $n): float
{
    return $n;
}

function takes_int(int $x): int
{
    return $x;
}

class F
{
    public float $f;
}

echo 'param=', var_export(takes_float(1), true), "\n";
echo 'ret=', var_export(returns_float(1), true), "\n";
$o = new F();
$o->f = 1;
echo 'prop=', var_export($o->f, true), "\n";

try {
    takes_int(1.5);
    echo "float_to_int_ok\n";
} catch (TypeError $e) {
    echo "float_to_int_TypeError\n";
}
--EXPECT--
param=1.0
ret=1.0
prop=1.0
float_to_int_TypeError
