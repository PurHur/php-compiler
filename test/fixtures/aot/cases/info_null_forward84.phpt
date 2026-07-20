--TEST--
AOT: defined(null) soft-null on 8.4 (#21281, re-#20254 info sibling)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo var_export(defined(null), true), "\n";
--EXPECT--
false
