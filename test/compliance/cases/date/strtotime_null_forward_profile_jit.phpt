--TEST--
date strtotime(null) — TypeError on 8.4 forward profile JIT (#19651, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    strtotime(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strtotime(): Argument #1 ($datetime) must be of type string, null given
