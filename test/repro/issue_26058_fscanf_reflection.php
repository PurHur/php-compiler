<?php
// #26058 — fscanf Reflection: return union + mixed &...$vars.
$r = new ReflectionFunction('fscanf');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(),
        ' type=', $p->hasType() ? (string) $p->getType() : 'NONE',
        ' byref=', $p->isPassedByReference() ? 'y' : 'n',
        ' variadic=', $p->isVariadic() ? 'y' : 'n', "\n";
}
