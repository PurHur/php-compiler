--TEST--
stdlib ini_get()/ini_set() — int option operand coerces JIT on 8.4 (#21312, reverts #17268 TypeError)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_dump(ini_get(123));
var_dump(ini_set(456, 'x'));
?>
--EXPECT--
bool(false)
bool(false)
