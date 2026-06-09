--TEST--
stdlib mb_check_encoding() UTF-8 validity (VM, issue #4571)
--FILE--
<?php
var_dump(function_exists('mb_check_encoding'));
var_dump(mb_check_encoding('café', 'UTF-8'));
var_dump(mb_check_encoding("\xFF", 'UTF-8'));
var_dump(mb_check_encoding(''));
var_dump(mb_check_encoding(['ok', 'café'], 'UTF-8'));
var_dump(mb_check_encoding(['bad', "\xFF"], 'UTF-8'));
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(false)
