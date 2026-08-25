<?php
/**
 * #34721 — AOT by-ref return of $o->prop when $o is untyped/mixed (re-#34717).
 */
class O34721
{
    public $x = 1;
}

function &f_untyped($o)
{
    return $o->x;
}

function &f_mixed(mixed $o)
{
    return $o->x;
}

function &f_object(object $o)
{
    return $o->x;
}

$o = new O34721();
$a = &f_untyped($o);
$a = 5;
echo 'untyped:';
var_dump($o->x);

$o2 = new O34721();
$b = &f_mixed($o2);
$b = 7;
echo 'mixed:';
var_dump($o2->x);

$o3 = new O34721();
$c = &f_object($o3);
$c = 9;
echo 'object:';
var_dump($o3->x);
