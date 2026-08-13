--TEST--
stdlib parse_ini_file('') JIT — ValueError empty filename (#30756, ext/standard/ini.c)
--JIT--
--FILE--
<?php
try {
    $empty = substr('x', 1);
    parse_ini_file($empty);
    echo "ok\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
parse_ini_file(): Argument #1 ($filename) cannot be empty
