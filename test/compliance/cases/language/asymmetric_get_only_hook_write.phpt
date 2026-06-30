--TEST--
Language: private(set) get-only hook write enforcement (#13983, Zend/zend_execute.c)
--FILE--
<?php
class C {
    private(set) string $x {
        get => 'hi';
    }
}
$c = new C();
echo $c->x, "\n";
try {
    $c->x = 'no';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
hi
Error: Cannot modify private(set) property C::$x from global scope
