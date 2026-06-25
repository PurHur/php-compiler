<?php

declare(strict_types=1);

$usage = getrusage();
var_export(array_key_exists(0, $usage));
echo "\n";
var_export(array_key_exists('ru_nvcsw', $usage));
echo "\n";
var_export(array_is_list($usage));
echo "\n";
