--TEST--
Language: promoted asymmetric visibility public private(set) (#9724, PHP 8.4 zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(
        public private(set) string $name,
    ) {
    }
}
$c = new C('hi');
echo $c->name, "\n";
try {
    $c->name = 'no';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
hi
Error: Cannot modify private(set) property C::$name from global scope
