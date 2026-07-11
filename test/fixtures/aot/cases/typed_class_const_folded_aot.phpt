--TEST--
AOT: typed class constants — folded fetch string/array (issue #16378, #3592)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80300) {
    die('skip typed class constants require PHP 8.3+');
}
?>
--FILE--
<?php
class K {
    const string S = 'abc';
    const array A = [1, 2];
}
echo K::S, "\n";
echo count(K::A), "\n";
--EXPECT--
abc
2
