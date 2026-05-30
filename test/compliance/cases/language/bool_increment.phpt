--TEST--
language bool pre/post increment (issue #3552)
--FILE--
<?php
$b = true;
$b++;
var_dump($b);
$b = false;
$b++;
var_dump($b);
$b = true;
$b--;
var_dump($b);
$b = false;
$b--;
var_dump($b);
--EXPECT--
bool(true)
bool(false)
bool(true)
bool(false)
