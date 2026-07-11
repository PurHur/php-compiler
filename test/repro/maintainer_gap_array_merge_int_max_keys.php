<?php

declare(strict_types=1);

$a = [PHP_INT_MAX => 'a'];
$b = [-PHP_INT_MAX => 'b'];
var_export(array_merge($a, $b));
echo "\n";
var_export(array_is_list(array_merge([PHP_INT_MAX => 1], [0 => 2])));
echo "\n";
