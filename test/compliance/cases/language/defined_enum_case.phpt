--TEST--
Language: defined() detects enum cases like Zend (zend_constants.c, #4972)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 2;
}
var_dump(defined('E::A'));
var_dump(defined('E::Z'));
echo (constant('E::A') === E::A) ? "same\n" : "diff\n";
enum U { case X; case Y; }
var_dump(defined('U::X'));
var_dump(defined('U::MISSING'));
--EXPECT--
bool(true)
bool(false)
same
bool(true)
bool(false)
