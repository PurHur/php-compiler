--TEST--
JIT: dl() returns false with enable_dl off (issue #3591)
--FILE--
<?php
var_dump(function_exists('dl'));
var_dump(@dl('nonexistent.so'));
--EXPECT--
bool(true)
bool(false)
