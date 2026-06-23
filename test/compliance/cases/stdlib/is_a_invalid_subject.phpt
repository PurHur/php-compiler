--TEST--
stdlib is_a() — null/scalar subject returns false (#10873, ext/standard/class.c)
--FILE--
<?php
var_dump(is_a(null, 'stdClass'));
var_dump(is_a(false, 'stdClass'));
var_dump(is_a(true, 'stdClass'));
var_dump(is_a(1, 'stdClass'));
var_dump(is_a(1, 'stdClass', true));
var_dump(is_a([], 'stdClass'));
class Widget {}
var_dump(is_a('Widget', 'Widget'));
--EXPECT--
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
