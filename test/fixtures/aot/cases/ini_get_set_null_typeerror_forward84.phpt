--TEST--
AOT ini_get(null) — soft-null DEP+false on 8.4 (#21312, reverts #20361)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo ini_get(null) === false ? "false\n" : "bad\n";
?>
--EXPECT--
false
