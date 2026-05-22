--TEST--
const + define() sum (issue #204 acceptance)
--FILE--
<?php
const X = 42;
define('Y', 1);
echo X + Y;
--EXPECT--
43
