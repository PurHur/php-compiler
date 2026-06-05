--TEST--
Language: interface typed constant inheritance — compatible class override (#5982)
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
    public const array X = [2, 3];
}
echo C::X[0], "\n";
--EXPECT--
2
