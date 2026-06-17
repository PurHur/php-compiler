--TEST--
Language: protected(set) parses and enforces set visibility (#9310, zend_compile.c)
--FILE--
<?php
class A {
    protected(set) string $x = 'ok';

    public function setX(string $v): void {
        $this->x = $v;
    }
}
$a = new A();
echo $a->x, "\n";
try {
    $a->x = 'nope';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$a->setX('from-method');
echo $a->x, "\n";
--EXPECT--
ok
Error: Cannot modify protected(set) property A::$x from global scope
from-method
