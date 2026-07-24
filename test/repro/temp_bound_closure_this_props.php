<?php
/**
 * Issue #22868 — auto-bound closure from (new T)->method() must keep $this properties alive.
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_closures.c
 */
class A
{
    public $x = 1;

    public function get()
    {
        return function () {
            return $this->x;
        };
    }

    public function g()
    {
        return (new ReflectionMethod($this, 'priv'))->getClosure($this);
    }

    private function priv()
    {
        return $this->x + 2;
    }
}

$c = (new A)->get();
var_export($c());
echo PHP_EOL;

$a = new A;
$c2 = $a->get();
var_export($c2());
echo PHP_EOL;

var_export(((new A)->get())());
echo PHP_EOL;

$rm = (new A)->g();
var_export($rm());
echo PHP_EOL;
