<?php

declare(strict_types=1);

$phantom = [];
foreach (['sockets', 'xsl', 'inotify'] as $module) {
    if (!extension_loaded($module)) {
        $c = get_defined_constants(true);
        $count = isset($c[$module]) ? count($c[$module]) : 0;
        if ($count > 0) {
            $phantom[] = $module;
        }
    }
}

if ([] !== $phantom) {
    echo 'phantom_buckets=', implode(',', $phantom), "\n";
    exit(1);
}

echo "ok\n";
