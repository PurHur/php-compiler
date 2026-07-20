--TEST--
AOT: function_exists(null) soft-null on 8.4 (#21281)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo var_export(function_exists(null), true), "\n";
--EXPECT--
false
