<?php

declare(strict_types=1);

// Issue #27998 — clearstatcache(filename:) named-only arg (php-src ext/standard/filestat.c)
$rf = new ReflectionFunction('clearstatcache');
echo 'return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '-', "\n";
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), ' def=',
        $p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : 'n/a',
        "\n";
}
clearstatcache(filename: __FILE__);
echo "named_filename_ok\n";
