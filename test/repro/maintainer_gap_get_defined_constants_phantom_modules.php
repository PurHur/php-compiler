<?php

declare(strict_types=1);

$phantom = [];
foreach (['inotify', 'sockets', 'xsl'] as $ext) {
    $loaded = extension_loaded($ext);
    $count = count(get_defined_constants(true)[$ext] ?? []);
    if (!$loaded && $count > 0) {
        $phantom[] = $ext;
    }
}

if ([] !== $phantom) {
    fwrite(STDERR, 'phantom_buckets='.implode(',', $phantom)."\n");
    exit(1);
}

echo "ok\n";
