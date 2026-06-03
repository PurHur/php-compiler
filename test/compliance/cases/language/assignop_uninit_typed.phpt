--TEST--
Language: compound assign on uninitialized typed property throws Error (#4930)
--FILE--
<?php
class T {
    public int $x;
}
$o = new T();
try {
    $o->x += 1;
} catch (Error $e) {
    echo 'Error: ', $e->getMessage(), "\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}

class S {
    public string $s;
}
$o2 = new S();
try {
    $o2->s .= 'x';
} catch (Error $e) {
    echo 'Error: ', $e->getMessage(), "\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Typed property T::$x must not be accessed before initialization
Error: Typed property S::$s must not be accessed before initialization
