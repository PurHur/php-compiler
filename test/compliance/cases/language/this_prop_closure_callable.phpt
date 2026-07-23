--TEST--
language: Closure assigned via $this->prop remains callable (issue #22656, Zend/zend_object_handlers.c)
--FILE--
<?php
class C {
    public $arrow;
    public $closure;
    public $fcc;

    public function __construct() {
        $this->arrow = fn() => 42;
        $this->closure = function () { return 7; };
        $this->fcc = strlen(...);
    }

    public function setFromMethod(): void {
        $this->arrow = fn() => 99;
    }
}

$o = new C;
var_export(is_callable($o->arrow));
echo "\n";
echo ($o->arrow)(), "\n";
var_export(is_callable($o->closure));
echo "\n";
echo ($o->closure)(), "\n";
var_export(is_callable($o->fcc));
echo "\n";
echo ($o->fcc)('ab'), "\n";

$o->setFromMethod();
var_export(is_callable($o->arrow));
echo "\n";
echo ($o->arrow)(), "\n";

class D { public $f; }
$d = new D;
$d->f = fn() => 1;
var_export(is_callable($d->f));
echo "\n";
$x = $d->f;
echo $x(), "\n";
--EXPECT--
true
42
true
7
true
2
true
99
true
1
