--TEST--
Language: typed class constant float accepts int literal (PHP 8.3, #4541)
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
    public const float F = 1;
}
echo C::F, "\n";
--EXPECT--
1
