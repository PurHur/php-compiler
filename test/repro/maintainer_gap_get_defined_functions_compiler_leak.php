<?php
declare(strict_types=1);

$internal = get_defined_functions()['internal'];
echo $internal[0], "\n";
var_export(function_exists('__compiler_is_superglobal_name'));
echo "\n";
var_export(in_array('__compiler_is_superglobal_name', $internal, true));
echo "\n";
