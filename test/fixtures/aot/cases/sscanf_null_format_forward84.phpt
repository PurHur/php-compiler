--TEST--
AOT: sscanf() null $format soft-null on 8.4 (#21521)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
// Constant null → compile-time soft-null fold (avoids StringVfscanf NestedJIT link).
$r = sscanf('abc', null);
echo gettype($r), ' ', (string) count($r), "\n";
?>
--EXPECT--
array 0
