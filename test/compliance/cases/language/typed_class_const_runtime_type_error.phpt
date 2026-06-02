--TEST--
Language: typed class constant non-constexpr mismatch — runtime TypeError (#4541)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80300) {
    die('skip typed class constants require PHP 8.3+');
}
?>
--FILE--
<?php
class C {
    public const string S = 1 + 0;
}
--EXPECT_EXIT--
255
