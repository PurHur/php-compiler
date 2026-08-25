--TEST--
AOT: filter_input_array() CLI NULL — no abort (#34580, ext/filter/filter.c)
--FILE--
<?php
declare(strict_types=1);
var_export(filter_input_array(INPUT_GET, FILTER_DEFAULT));
echo "\n";
var_export(filter_input_array(INPUT_GET, ['x' => FILTER_VALIDATE_INT]));
echo "\n";
--EXPECT--
NULL
NULL
--EXPECT_EXIT--
0
