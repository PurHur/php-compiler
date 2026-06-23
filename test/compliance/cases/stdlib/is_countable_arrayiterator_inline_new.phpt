--TEST--
stdlib is_countable() inline new ArrayIterator() (#10900, ext/standard/type.c)
--FILE--
<?php
var_export(is_countable(new ArrayIterator([])));
echo "\n";
var_export(is_countable(new ArrayObject([])));
echo "\n";
--EXPECT--
true
true
