--TEST--
Language: typed interface constant override must be compatible (PHP 8.3, issue #5980)
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
class C implements I {
    public const int X = 1;
}
echo "unreachable\n";
--EXPECTF--
Fatal error: Type of C::X must be compatible with I::X of type string in %s on line %d

