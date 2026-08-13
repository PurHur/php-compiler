--TEST--
Language: typed class constant type mismatch — compile-time TypeError (#3592)
--ENV--
PHP_COMPILER_PROFILE=8.3
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80300) {
    die('skip typed class constants require PHP 8.3+');
}
?>
--FILE--
<?php
class C {
    public const string S = 1;
}
--EXPECT_EXIT--
255
