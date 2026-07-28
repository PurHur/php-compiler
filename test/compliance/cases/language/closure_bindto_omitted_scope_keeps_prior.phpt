--TEST--
Language: Closure::bindTo($obj) omitted scope keeps prior (private) — Zend Error (#24244, zend_closures.c)
--FILE--
<?php
class A {
    private $x = 1;
    public function get() {
        return function () { return $this->x; };
    }
}
class B {
    private $x = 2;
}

function invoke($label, $fn) {
    try {
        $v = $fn();
        echo $label, '=', $v, "\n";
    } catch (Throwable $e) {
        echo $label, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$f = (new A())->get();
echo 'orig=', $f(), "\n";

// Fresh method closures per case: catching Error from a rebound closure twice can
// leave the original Closure uncallable on the VM (pre-existing lifetime issue).
$f1 = (new A())->get();
invoke('omitted', $f1->bindTo(new B()));

$f2 = (new A())->get();
invoke('static', $f2->bindTo(new B(), 'static'));

$f3 = (new A())->get();
invoke('scopeA', $f3->bindTo(new B(), A::class));

$f4 = (new A())->get();
invoke('scopeB', $f4->bindTo(new B(), B::class));
?>
--EXPECT--
orig=1
omitted Error: Cannot access private property B::$x
static Error: Cannot access private property B::$x
scopeA Error: Cannot access private property B::$x
scopeB=2
