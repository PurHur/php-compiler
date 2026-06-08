--TEST--
str_contains() named haystack/needle arguments (VM, issue #6747)
--FILE--
<?php
var_dump(str_contains(haystack: 'abc', needle: 'b'));
--EXPECT--
bool(true)
