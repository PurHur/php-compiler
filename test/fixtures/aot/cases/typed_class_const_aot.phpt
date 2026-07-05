--TEST--
AOT: typed class constants — string and array (#16378, Zend/zend_constants.c)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80300) {
    die('skip typed class constants require PHP 8.3+');
}
if (!PHPCompiler\CompilerVersion::supportsTypedClassConstants()) {
    die('skip typed class constants require CompilerVersion 8.3+ profile');
}
?>
--FILE--
<?php
class K { const string S = "abc"; const array A = [1, 2]; }
echo K::S, "\n"; echo count(K::A), "\n";
--EXPECT--
abc
2
