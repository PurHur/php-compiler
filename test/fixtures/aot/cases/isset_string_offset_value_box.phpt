--TEST--
isset() on string offset through VALUE box (#32622)
--FILE--
<?php
$s = "hello";
var_dump(isset($s[0]));
var_dump(isset($s[4]));
var_dump(isset($s[5]));
var_dump(isset($s[-1]));
var_dump(isset($s[-5]));
var_dump(isset($s[-6]));
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(false)
