--TEST--
stdlib parse_ini_file('') — ValueError for empty filename (#11033, ext/standard/ini.c)
--FILE--
<?php
try {
    parse_ini_file('');
    echo "ok\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
parse_ini_file(): Argument #1 ($filename) cannot be empty
