--TEST--
stdlib array_any()/array_all() inline [] in nested expression (#14516, ext/standard/array.c)
--FILE--
<?php
$cb = static fn ($v) => (bool) $v;
var_export(array_any([], $cb));
echo "\n";
var_export(array_all([], $cb));
echo "\n";
?>
--EXPECT--
false
true
