<?php
declare(strict_types=1);

// #28898 — pcntl_signal Reflection: $handler untyped; $restart_syscalls default true
$r = new ReflectionFunction('pcntl_signal');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '-';
    if ($p->isDefaultValueAvailable()) {
        echo ' default=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
