<?php
// Repro #24843 — array_filter($a, mode: …) omitted callback + Reflection defaults
$a = [1, 2, 3, 4];
var_export(array_filter($a, mode: ARRAY_FILTER_USE_KEY));
echo "\n";
$b = [0, 1, '', 'x'];
var_export(array_filter(array: $b, mode: ARRAY_FILTER_USE_KEY));
echo "\n";
$r = new ReflectionFunction('array_filter');
echo 'required=', $r->getNumberOfRequiredParameters(), ' argc=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->hasType()) {
        echo ':', $p->getType();
    }
    echo $p->isOptional() ? ' OPT' : ' REQ';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=', json_encode($p->getDefaultValue());
    }
    echo "\n";
}
