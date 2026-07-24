--TEST--
Language: temp-bound auto-closure / ReflectionMethod::getClosure keep $this props (#22868, zend_closures.c)
--FILE--
<?php
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
echo "\n";

$a = new A;
$c2 = $a->get();
var_export($c2());
echo "\n";

var_export(((new A)->get())());
echo "\n";

$rm = (new A)->g();
var_export($rm());
echo "\n";
--EXPECT--
1
1
1
3
