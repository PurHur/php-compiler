--TEST--
str_starts_with()/str_ends_with() named haystack/needle arguments (VM, issue #16616)
--FILE--
<?php
var_dump(str_starts_with(haystack: 'abcdef', needle: 'abc'));
var_dump(str_ends_with(haystack: 'abcdef', needle: 'def'));
--EXPECT--
bool(true)
bool(true)
