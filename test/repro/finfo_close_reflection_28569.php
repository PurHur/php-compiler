<?php
/** Repro #28569 — finfo_close Reflection return must be bool. */
$r = new ReflectionFunction('finfo_close');
echo (string) ($r->getReturnType() ?? 'untyped'), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', (string) ($p->getType() ?? '?'), "\n";
}
