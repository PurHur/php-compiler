--TEST--
Language: public private(set) read + write enforcement (#13914, Zend/zend_compile.c)
--FILE--
<?php
class B {
    public private(set) string $label = 'hi';
}
$b = new B();
echo $b->label, "\n";
try {
    $b->label = 'no';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
hi
Error: Cannot modify private(set) property B::$label from global scope
