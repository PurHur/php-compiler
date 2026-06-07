--TEST--
Scalar union types compile on params and properties (#6833)
--FILE--
<?php
declare(strict_types=1);
function f(int|string $x): int|string {
    return $x;
}
echo f(1), "\n";
echo f('a'), "\n";

class C {
    public int|string $p;
}
$c = new C();
var_export(isset($c->p));
echo "\n";
try {
    echo $c->p;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
$c->p = 42;
echo $c->p, "\n";
?>
--EXPECT--
1
a
false
Typed property C::$p must not be accessed before initialization
42
