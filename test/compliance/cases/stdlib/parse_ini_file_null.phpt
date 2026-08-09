--TEST--
stdlib parse_ini_file(null) — ValueError empty filename (#18699, ext/standard/basic_functions.c)
--FILE--
<?php
try {
    parse_ini_file(null);
    echo "no_throw\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
parse_ini_file(): Argument #1 ($filename) must not be empty
