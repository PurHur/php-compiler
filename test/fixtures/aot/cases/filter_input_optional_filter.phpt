--TEST--
AOT: filter_input() optional filter arg defaults to FILTER_DEFAULT (#14152)
--FILE--
<?php
echo 'two_arg:';
var_export(filter_input(INPUT_GET, 'missing'));
echo "\n";
echo 'three_arg:';
var_export(filter_input(INPUT_GET, 'missing', FILTER_DEFAULT));
echo "\n";
--EXPECT--
two_arg:NULL
three_arg:NULL
--EXPECT_EXIT--
0
