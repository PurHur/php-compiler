--TEST--
AOT php_uname() invalid $mode ValueError under PROFILE=8.4 (#28136)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    php_uname('z');
    echo "fail\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
echo php_uname('s') !== '' ? "ok\n" : "empty\n";
?>
--EXPECT--
php_uname(): Argument #1 ($mode) must be one of "a", "m", "n", "r", "s", or "v"
ok
