--TEST--
Language: undefined static method Error wording matches Zend (#27921, zend_execute_API.c)
--FILE--
<?php
class C {}
try {
    C::nope();
} catch (Error $e) {
    echo 'static:', $e->getMessage(), "\n";
}
try {
    (new C())->nope();
} catch (Error $e) {
    echo 'inst:', $e->getMessage(), "\n";
}
try {
    stdClass::undefined();
} catch (Error $e) {
    echo 'std:', $e->getMessage(), "\n";
}
--EXPECT--
static:Call to undefined method C::nope()
inst:Call to undefined method C::nope()
std:Call to undefined method stdClass::undefined()
