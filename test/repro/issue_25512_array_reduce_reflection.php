<?php
$r = new ReflectionFunction('array_reduce');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' ', $p->hasType() ? (string)$p->getType() : '-', ' def=', $p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : '-', "\n";
}
echo 'ret=', $r->hasReturnType() ? (string)$r->getReturnType() : '-', "\n";
