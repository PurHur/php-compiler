--TEST--
Language: typed class constant inheritance — compatible override (#5953)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80300) {
    die('skip typed class constants require PHP 8.3+');
}
?>
--FILE--
<?php
class Base { public const string FOO = 'a'; }
class Child extends Base { public const string FOO = 'b'; }
echo Child::FOO, "\n";
--EXPECT--
b
