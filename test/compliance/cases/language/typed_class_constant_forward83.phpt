--TEST--
Language: typed class constants — interface + final class (issue #4511, Zend zend_compile.c PHP 8.3)
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
interface I { const string X = 'a'; }
final class C { final const string Y = 'b'; }
echo I::X, C::Y, "\n";
--EXPECT--
ab
