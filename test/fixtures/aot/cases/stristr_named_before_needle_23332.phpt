--TEST--
AOT: stristr() named before_needle compiles and matches Zend (#23332)
--FILE--
<?php
echo stristr(haystack: 'AbCd', needle: 'bc', before_needle: true), "\n";
echo stristr('ABC-DEF', '-', true), "\n";
echo stristr('ABC-DEF', 'def'), "\n";
--EXPECT--
A
ABC
DEF
