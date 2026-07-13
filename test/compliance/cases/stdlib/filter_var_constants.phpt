--TEST--
stdlib filter_var() FILTER_* constants are usable (issue #9588, ext/filter/filter.c)
--FILE--
<?php
declare(strict_types=1);

var_export(filter_var('42', FILTER_VALIDATE_INT));
echo "\n";
var_export(filter_var('not-int', FILTER_VALIDATE_INT));
echo "\n";
var_export(filter_var('1.2', FILTER_VALIDATE_FLOAT));
echo "\n";
var_export(filter_var('x', FILTER_SANITIZE_STRING));
echo "\n";
--EXPECT--
42
false
1.2
'x'

