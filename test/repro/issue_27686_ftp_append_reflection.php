<?php

$r = new ReflectionFunction('ftp_append');
$ps = [];
foreach ($r->getParameters() as $p) {
    $ps[] = $p->getName().':'.(string) ($p->getType() ?? '?').($p->isOptional() ? ' opt' : '');
}
echo 'ret=', (string) ($r->getReturnType() ?? 'untyped'), ' [', implode(', ', $ps), "]\n";
$mode = $r->getParameters()[3];
echo 'mode_default=', var_export($mode->getDefaultValue(), true);
echo ' const=', var_export($mode->getDefaultValueConstantName(), true), "\n";
