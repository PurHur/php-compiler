--TEST--
AOT: filter_var() with runtime filter id (issue #34450)
--FILE--
<?php
$f = FILTER_VALIDATE_INT;
var_dump(filter_var('42', $f));
var_dump(filter_var('nope', $f));
$base = 200;
$computed = $base + 57; // FILTER_VALIDATE_INT === 257
var_dump(filter_var('7', $computed));
--EXPECT--
int(42)
bool(false)
int(7)
--EXPECT_EXIT--
0
