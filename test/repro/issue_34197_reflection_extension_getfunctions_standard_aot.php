<?php
/**
 * AOT: ReflectionExtension::getFunctions('standard') must include is_* and strptime (#34197).
 */
declare(strict_types=1);

$funcs = (new ReflectionExtension('standard'))->getFunctions();
echo 'count=', count($funcs), "\n";
$missing = [
    'is_array', 'is_bool', 'is_double', 'is_float', 'is_int', 'is_integer',
    'is_long', 'is_null', 'is_object', 'is_string', 'strptime',
];
foreach ($missing as $name) {
    echo $name, '=', isset($funcs[$name]) ? 'yes' : 'no', "\n";
}
