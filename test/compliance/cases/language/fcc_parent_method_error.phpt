--TEST--
Language: parenthesized inherited instance-method FCC Error message (#14928, Zend/zend_compile.c)
--FILE--
<?php
class Parent_ {
    public function parentMethod(): int { return 1; }
}
class Child extends Parent_ {}
try {
    (Child::parentMethod)(...);
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    Child::parentMethod(...);
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Undefined constant Child::parentMethod
Error: Non-static method Parent_::parentMethod() cannot be called statically
