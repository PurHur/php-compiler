<?php
// #27943 — shm_attach Reflection SysvSharedMemory|false + ?int $size
$r = new ReflectionFunction('shm_attach');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
foreach ($r->getParameters() as $p) {
    echo '$', $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : 'NONE';
    if ($p->isDefaultValueAvailable()) {
        echo ' default=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
$d = new ReflectionFunction('shm_detach');
$p0 = $d->getParameters()[0];
echo 'shm_detach $shm=', $p0->hasType() ? (string) $p0->getType() : 'NONE', "\n";
