<?php
declare(strict_types=1);

// #28843 — pcntl_async_signals Reflection: bool return; ?bool $enable = null
if (!function_exists('pcntl_async_signals')) {
    echo "missing\n";
    exit(0);
}

$r = new ReflectionFunction('pcntl_async_signals');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '-';
    if ($p->isDefaultValueAvailable()) {
        echo ' default=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
