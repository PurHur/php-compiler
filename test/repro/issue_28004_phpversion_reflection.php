<?php
// Repro #28004 — phpversion Reflection must be ?string=null → string|false
$r = new ReflectionFunction('phpversion');
$p = $r->getParameters()[0];
echo 'name=', $p->getName(), ' type=', $p->getType(), ' opt=', (int) $p->isOptional();
if ($p->isDefaultValueAvailable()) {
    echo ' def=', var_export($p->getDefaultValue(), true);
} else {
    echo ' def=N/A';
}
echo ' return=', $r->getReturnType(), "\n";
echo 'bare=', is_string(phpversion()) ? 'ok' : 'bad', "\n";
echo 'named=', is_string(phpversion(extension: 'json')) ? 'ok' : 'bad', "\n";
echo 'unknown=', var_export(phpversion('___no_such_ext___'), true), "\n";
