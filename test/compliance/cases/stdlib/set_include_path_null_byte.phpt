--TEST--
stdlib set_include_path() null byte in path must ValueError (php-src ext/standard/dir.c, #16749)
--FILE--
<?php
try {
    set_include_path("\x00");
    echo "no error\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
set_include_path(): Argument #1 ($include_path) must not contain any null bytes
--CREDITS--
PurHur/php-compiler issue #16749
