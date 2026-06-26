<?php

declare(strict_types=1);

$a = [1, '2'];
sort($a);
echo 'int_string_ok=';
var_export($a);
echo "\n";

$b = [1, 2.5];
sort($b);
echo 'int_float_ok=';
var_export($b);
echo "\n";

$c = [false, true, true];
sort($c);
echo 'bool_ok=';
var_export($c);
echo "\n";
