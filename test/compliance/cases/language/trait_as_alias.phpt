--TEST--
trait method alias via `as` keeps original name (issue #22718 / #3238)
--FILE--
<?php
trait T {
    public function f(): int {
        return 1;
    }
    public function g(): int {
        return 2;
    }
}
class C {
    use T {
        f as renamed;
    }
}
$c = new C();
var_export(method_exists($c, 'f'));
echo "\n";
var_export(method_exists($c, 'renamed'));
echo "\n";
echo $c->f(), $c->renamed(), $c->g();
--EXPECT--
true
true
112
