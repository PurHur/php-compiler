<?php
// AOT: ReflectionExtension::getFunctions('standard') includes is_* + strptime (#34197).
$fns = (new ReflectionExtension('standard'))->getFunctions();
$need = [
    'is_array', 'is_bool', 'is_double', 'is_float', 'is_int', 'is_integer',
    'is_long', 'is_null', 'is_object', 'is_string', 'strptime',
];
$missing = [];
foreach ($need as $n) {
    if (!is_array($fns) || !isset($fns[$n]) || !($fns[$n] instanceof ReflectionFunction)) {
        $missing[] = $n;
    }
}
echo 'count=', is_array($fns) ? count($fns) : 'n/a';
echo ' missing=', count($missing);
echo ' is_array=', is_array($fns) && isset($fns['is_array']) && $fns['is_array'] instanceof ReflectionFunction ? '1' : '0';
echo ' strptime=', is_array($fns) && isset($fns['strptime']) && $fns['strptime'] instanceof ReflectionFunction ? '1' : '0';
echo "\n";
