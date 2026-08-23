<?php
/**
 * #34178 — AOT: ReflectionExtension::getFunctions must not return NULL.
 */
$e = new ReflectionExtension('date');
$funcs = $e->getFunctions();
echo 'type=', gettype($funcs);
if (is_array($funcs)) {
    echo ' count=', count($funcs);
    echo ' date=', isset($funcs['date']) ? '1' : '0';
    echo ' phantom=', isset($funcs['__compiler_strlen']) ? '1' : '0';
}
echo "\n";
