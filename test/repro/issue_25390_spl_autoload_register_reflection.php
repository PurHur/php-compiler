<?php
declare(strict_types=1);

// #25390 — spl_autoload_register Reflection ?callable=null, throw=true
$rf = new ReflectionFunction('spl_autoload_register');
echo 'arity=', $rf->getNumberOfParameters(), ' req=', $rf->getNumberOfRequiredParameters(), "\n";
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), ' ', ($p->isOptional() ? 'OPT' : 'REQ'),
        ' type=', ($p->hasType() ? (string) $p->getType() : '-');
    if ($p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
