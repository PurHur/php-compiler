--TEST--
function_exists named function argument (JIT, issue #23435)
--FILE--
<?php
var_export(function_exists(function: 'strlen'));
echo "\n";
--EXPECT--
true
