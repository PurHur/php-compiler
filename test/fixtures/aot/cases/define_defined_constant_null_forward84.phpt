--TEST--
AOT: define(null) soft-null on 8.4 (#21281, re-#19652)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo var_export(define(null, 1), true), "\n";
--EXPECT--
true
