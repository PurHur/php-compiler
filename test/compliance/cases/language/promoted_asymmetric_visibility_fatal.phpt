--TEST--
Language: promoted public private(set) (#15368, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(
        public private(set) string $name,
    ) {
    }
}
$c = new C('alice');
echo $c->name, "\n";
try {
    $c->name = 'bob';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
alice
Error: Cannot modify private(set) property C::$name from global scope
