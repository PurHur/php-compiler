--TEST--
AOT: error_log(null) soft-null coerce on 8.4 forward profile (#24178, reverts #23858, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(error_log(null));
echo "\n";
?>
--EXPECT--
true
