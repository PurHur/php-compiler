--TEST--
Language: typed class constant inheritance — incompatible child type (#5953)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80300) {
    die('skip typed class constants require PHP 8.3+');
}
?>
--FILE--
<?php
class Base { public const string FOO = 'a'; }
class Bad extends Base { public const int FOO = 1; }
echo "compiled\n";
--EXPECT_EXIT--
255
