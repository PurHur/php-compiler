--TEST--
Language: first-class callable default parameter must compile-error (#9697, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public function f(Closure $c = strlen(...)): int {
        return $c('abc');
    }
}
--EXPECT_EXIT--
255
