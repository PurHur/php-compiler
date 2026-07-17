--TEST--
stdlib mb_ereg()/mb_eregi(null) — TypeError on 8.4 forward profile (#20261, ext/mbstring/php_mbregex.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['mb_ereg', 'mb_eregi'] as $fn) {
    try {
        $fn(null, 'abc');
        echo "$fn null: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
    try {
        $fn('', 'abc');
        echo "$fn empty: uncaught\n";
    } catch (ValueError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
mb_ereg(): Argument #1 ($pattern) must be of type string, null given
mb_ereg(): Argument #1 ($pattern) must not be empty
mb_eregi(): Argument #1 ($pattern) must be of type string, null given
mb_eregi(): Argument #1 ($pattern) must not be empty
