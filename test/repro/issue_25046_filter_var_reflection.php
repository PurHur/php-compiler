<?php
// Repro #25046 — filter_var Reflection defaults/types (ext/filter/filter.stub.php)
$r = new ReflectionFunction('filter_var');
echo 'required=', $r->getNumberOfRequiredParameters(), ' argc=', $r->getNumberOfParameters(), "\n";
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->hasType()) {
        echo ':', $p->getType();
    }
    echo $p->isOptional() ? ' OPT' : ' REQ';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=', var_export($p->getDefaultValue(), true);
    } elseif ($p->isOptional()) {
        echo '=?';
    }
    echo "\n";
}
echo 'FILTER_DEFAULT=', FILTER_DEFAULT, "\n";
var_export(filter_var('x'));
echo "\n";
var_export(filter_var(value: 'y', filter: FILTER_DEFAULT, options: 0));
echo "\n";
