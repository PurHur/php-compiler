--TEST--
Language: typed interface constants (PHP 8.3, issue #5980)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80300) {
    die('skip typed interface constants require PHP 8.3+');
}
?>
--FILE--
<?php
interface I {
    public const string X = 'a';
}
class C implements I {}
echo C::X, "\n";
--EXPECT--
a

