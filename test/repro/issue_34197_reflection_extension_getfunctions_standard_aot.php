<?php
/**
 * AOT: ReflectionExtension::getFunctions('standard') ownership remap (#34197).
 * Zend/VM: count=532 with is_* and strptime; AOT pre-fix: count=521, missing 11 names.
 */
$e = new ReflectionExtension('standard');
$f = $e->getFunctions();
echo 'type=', gettype($f);
echo ' count=', is_array($f) ? count($f) : 'n/a';
$checks = [
    'is_array', 'is_bool', 'is_double', 'is_float', 'is_int', 'is_integer',
    'is_long', 'is_null', 'is_object', 'is_string', 'strptime',
];
foreach ($checks as $name) {
    echo ' ', $name, '=', is_array($f) && array_key_exists($name, $f) ? '1' : '0';
}
echo "\n";
