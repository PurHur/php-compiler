--TEST--
AOT: global const and folded define() (issue #204)
--FILE--
<?php
const X = 42;
define('Y', 1);
echo X + Y;
--EXPECT--
43
