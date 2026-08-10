<?php

$r = new ReflectionFunction('ftp_mlsd');
$ps = [];
foreach ($r->getParameters() as $p) {
    $ps[] = $p->getName().':'.(string) ($p->getType() ?? '?').($p->isOptional() ? ' opt' : '');
}
echo 'ret=', (string) ($r->getReturnType() ?? 'untyped'), ' [', implode(', ', $ps), "]\n";
try {
    ftp_mlsd(ftp: null, directory: '.');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
