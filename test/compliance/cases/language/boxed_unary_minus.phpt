--TEST--
Language: boxed unary minus stays int for numeric strings/longs (zendi_negate_function, #32442)
--FILE--
<?php
function letters()
{
    return '5';
}
function five()
{
    return 5;
}
function onePointFive()
{
    return 1.5;
}

$s = letters();
var_dump(-$s);

$n = five();
var_dump(-$n);

$f = onePointFive();
var_dump(-$f);

$local = '5';
var_dump(-$local);
?>
--EXPECT--
int(-5)
int(-5)
float(-1.5)
int(-5)
