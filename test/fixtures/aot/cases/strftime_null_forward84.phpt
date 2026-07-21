--TEST--
AOT strftime(null)/gmstrftime(null) soft-null → false on 8.4 (#21582)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
// var_export (not ===) so AOT keeps the compile-time null fold without
// emitting a live __compiler_strftime call (pre-existing AOT link gap).
var_export(strftime(null));
var_export(gmstrftime(null));
--EXPECT--
falsefalse
