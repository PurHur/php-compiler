--TEST--
stdlib timezone_name_from_abbr(null) — TypeError on 8.4 profile (#18797, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    timezone_name_from_abbr(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
timezone_name_from_abbr(): Argument #1 ($abbr) must be of type string, null given
