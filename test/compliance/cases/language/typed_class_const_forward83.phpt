--TEST--
Language: typed class constants — array and string (issue #3592, Zend zend_constants.c)
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
    public const array X = [1, 2];
    public const string S = 'a';
}
echo C::X[0], C::S, "\n";
--EXPECT--
1a
