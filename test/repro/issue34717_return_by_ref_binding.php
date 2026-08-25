<?php
// AOT by-ref return: static/global/property must alias caller storage (#34717).
// php-src: Zend/zend_execute.c ZEND_RETURN / Zend/zend_vm_def.h ZEND_RETURN ref path

function &static_ref()
{
    static $x = 1;

    return $x;
}

$a = &static_ref();
$a = 5;
var_dump(static_ref());

$g = 1;
function &global_ref()
{
    global $g;

    return $g;
}
$b = &global_ref();
$b = 5;
var_dump(global_ref());

class Box34717
{
    public $x = 1;
}

function &prop_ref($o)
{
    return $o->x;
}
$o = new Box34717();
$c = &prop_ref($o);
$c = 5;
var_dump($o->x);

// Direct by-value use of by-ref return as call arg (php-cfg dead temp, #34717 / #8561)
function &one()
{
    static $n = 1;

    return $n;
}
var_dump(one());
