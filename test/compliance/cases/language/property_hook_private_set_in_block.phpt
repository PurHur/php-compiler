--TEST--
Language: property hook block private(set) asymmetric visibility (#9872, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public string $x {
        get => 'g';
        private(set);
    }
}
$c = new C();
echo $c->x, "\n";
try {
    $c->x = 'bad';
    echo "no-error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
g
Cannot modify private(set) property C::$x from global scope
