--TEST--
define() with dynamic name on JIT (issue #4435)
--FILE--
<?php
$name = 'MY_CONST_' . 'X';
var_dump(define($name, 123));
var_dump(defined($name));
var_dump(constant($name));
var_dump(define($name, 456));
var_dump(constant($name));
--EXPECT--
bool(true)
bool(true)
int(123)
bool(false)
int(123)
