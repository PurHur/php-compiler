--TEST--
Language: promoted asymmetric visibility public private(set) (#9622, PHP 8.4 zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(
        public private(set) string $name,
    ) {}
}
$c = new C('C');
echo $c->name, "\n";
try {
    $c->name = 'nope';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
C
Error: Cannot modify private(set) property C::$name from global scope
