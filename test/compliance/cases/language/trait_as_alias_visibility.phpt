--TEST--
trait `as` with visibility + alias keeps original (#22718)
--FILE--
<?php
trait T {
    public function foo() {
        return 1;
    }
}
class C {
    use T {
        foo as protected foo2;
    }
    public function call() {
        return $this->foo() + $this->foo2();
    }
}
$c = new C();
echo $c->call(), "\n";
var_export(method_exists($c, 'foo'));
echo "\n";
var_export(method_exists($c, 'foo2'));
echo "\n";
--EXPECT--
2
true
true
