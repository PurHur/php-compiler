--TEST--
stdlib array_pop()/array_shift() — empty array_pop silent, array_shift warns (#4791, #10194)
--FILE--
<?php
$a = [];
var_export(array_pop($a));
echo "\n";
$b = [];
var_export(array_shift($b));
echo "\n";
--EXPECT--
PHP Warning:  array_shift(): Trying to shift an empty array
NULL
NULL
