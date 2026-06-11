--TEST--
stdlib filter_input() JIT with PhpInputFilter enum (#7284)
--JIT--
--FILE--
<?php
var_export(filter_input(PhpInputFilter::Get, 'missing', FILTER_VALIDATE_INT) === null);
echo "\n";
var_export(filter_input(INPUT_GET, 'missing', FILTER_VALIDATE_INT) === null);
echo "\n";
--EXPECT--
true
true
