--TEST--
Language: interface typed constant inheritance — incompatible class override (#5982)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80300) {
    die('skip typed interface constants require PHP 8.3+');
}
?>
--FILE--
<?php
interface I {
    public const array X = [1];
}
class C implements I {
    public const string X = 'not-array';
}
echo "compiled\n";
--EXPECT_EXIT--
255
