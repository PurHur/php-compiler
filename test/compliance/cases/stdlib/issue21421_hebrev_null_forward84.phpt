--TEST--
stdlib hebrev(null) soft-null on PROFILE=8.4 (#21421, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$r = hebrev(null);
echo var_export($r, true), "\n";
echo function_exists('hebrev') ? "yes\n" : "no\n";
?>
--EXPECT--
''
yes
