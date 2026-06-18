--TEST--
Language: private(set) parses and enforces set visibility (#7460, #9161, zend_compile.c)
--FILE--
<?php
class A {
    private(set) string $x = 'hi';
}
$a = new A();
echo $a->x, "\n";
try {
    $a->x = 'no';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
hi
Error: Cannot modify private(set) property A::$x from global scope
