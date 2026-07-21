--TEST--
stdlib ord(null) — soft-null coerce on 8.4 forward profile JIT (#21222, supersedes #19319 TypeError)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$character = null;
try {
    echo ord($character), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
0
