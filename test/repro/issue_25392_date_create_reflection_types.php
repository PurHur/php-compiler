<?php
declare(strict_types=1);

// #25392 — date_create Reflection return/types/defaults (re-#23276)
$rf = new ReflectionFunction('date_create');
echo 'return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none', "\n";
echo 'arity=', $rf->getNumberOfParameters(), ' req=', $rf->getNumberOfRequiredParameters(), "\n";
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '?';
    if ($p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    } elseif ($p->isOptional()) {
        echo ' def=OPT';
    } else {
        echo ' def=REQ';
    }
    echo "\n";
}
$d = date_create(datetime: '2020-01-02 03:04:05');
echo $d->format('Y-m-d H:i:s'), "\n";
