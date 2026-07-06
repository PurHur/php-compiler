--TEST--
AOT: get_defined_functions() exclude_disabled: named parameter (#16902, basic_functions.c)
--FILE--
<?php
$filtered = get_defined_functions(exclude_disabled: true);
echo array_key_exists('internal', $filtered) && array_key_exists('user', $filtered) ? "shape-ok\n" : "shape-bad\n";
$all = get_defined_functions();
echo count($all['internal']) >= count($filtered['internal']) ? "count-ok\n" : "count-bad\n";
--EXPECT--
shape-ok
count-ok
