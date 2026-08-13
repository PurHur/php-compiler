--TEST--
Language: typed class constant union type mismatch — compile-time TypeError (#6886)
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
    public const int|string X = true;
}
--EXPECT_EXIT--
255
