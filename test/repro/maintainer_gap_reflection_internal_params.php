<?php
// Zend: internal ReflectionFunction reports arginfo arity (#11453).
$map = (new ReflectionFunction('array_map'))->getNumberOfParameters();
$strlen = (new ReflectionFunction('strlen'))->getNumberOfParameters();
$json = (new ReflectionFunction('json_encode'))->getNumberOfParameters();
if (3 === $map && 1 === $strlen && 3 === $json) {
    echo "internal_reflection_params_ok\n";
} else {
    echo "internal_reflection_params_fail map=$map strlen=$strlen json=$json\n";
}
