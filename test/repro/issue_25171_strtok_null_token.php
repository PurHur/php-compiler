<?php

declare(strict_types=1);

// Repro #25171 — strtok($string, null) === false; Reflection string/?string=null
var_export(strtok('a.b.c', null));
echo "\n";
$r = new ReflectionFunction('strtok');
foreach ($r->getParameters() as $p) {
    echo $p->getName(),
        ' type=', $p->hasType() ? (string) $p->getType() : '-',
        ' null=', ($p->hasType() && $p->getType()->allowsNull()) ? '1' : '0',
        ' opt=', $p->isOptional() ? '1' : '0';
    if ($p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
