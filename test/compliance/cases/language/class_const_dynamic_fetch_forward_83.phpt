--TEST--
Language: dynamic class constant fetch under PHP_COMPILER_PROFILE=8.3 (#23760, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
class C {
    const RED = 'red';
}
$n = 'RED';
echo C::{$n}, "\n";
$cls = 'C';
echo $cls::{$n}, "\n";
$o = new C();
echo $o::{$n}, "\n";
$bad = 'MISSING';
try {
    C::{$bad};
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $o::{$bad};
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
red
red
red
Error: Undefined constant C::MISSING
Error: Undefined constant C::MISSING
