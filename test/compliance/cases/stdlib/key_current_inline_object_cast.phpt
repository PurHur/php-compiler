--TEST--
stdlib key()/current() — inline (object)[] cast (#15209, ext/standard/array.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

var_export(key((object) []));
echo "\n";
var_export(current((object) []));
echo "\n";
--EXPECT--
NULL
false
