<?php
/**
 * #34717 — AOT by-ref function return must alias the live cell (Zend ZEND_RETURN_BY_REF).
 */
function &f_static()
{
    static $x = 1;

    return $x;
}

$g = 1;
function &f_global()
{
    global $g;

    return $g;
}

class C34717
{
    public $x = 1;

    public function &get()
    {
        return $this->x;
    }
}

echo 'static:';
var_dump(f_static());
$a = &f_static();
$a = 5;
var_dump(f_static());

echo 'global:';
$b = &f_global();
$b = 7;
var_dump($g);
var_dump(f_global());

echo 'prop:';
$o = new C34717();
$c = &$o->get();
$c = 9;
var_dump($o->x);
