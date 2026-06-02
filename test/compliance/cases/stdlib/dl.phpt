--TEST--
stdlib dl() — registered stub returns false with enable_dl off (issue #3779)
--FILE--
<?php
var_dump(function_exists('dl'));
var_dump(@dl('nonexistent.so'));
--EXPECT--
bool(true)
bool(false)
