--TEST--
Language: PHP 8.3+ arbitrary static init with FCC (#31168, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
function f() {
    static $x = strlen(...);
    return $x('ab');
}
echo f(), ",", f(), "\n";
--EXPECT--
2,2
