--TEST--
Declared property scalar union types compile and enforce initialization (#6850)
--FILE--
<?php
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
$c->p = 'x';
echo $c->p, "\n";
$c->p = 7;
echo $c->p, "\n";
?>
--EXPECT--
false
Typed property C::$p must not be accessed before initialization
x
7
