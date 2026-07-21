--TEST--
AOT: ord(null) soft-null coerce under PROFILE=8.4 (#21668; DEP text on VM/JIT)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// DEP parameter index is verified on VM/JIT; AOT checks coerce result (null → 0).
$character = null;
echo ord($character), "\n";
?>
--EXPECT--
0
