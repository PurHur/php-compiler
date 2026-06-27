<?php

declare(strict_types=1);

$s = gc_status();
foreach (['running', 'protected', 'full', 'buffer_size'] as $key) {
    if (!array_key_exists($key, $s)) {
        fwrite(STDERR, "missing key: {$key}\n");
        exit(1);
    }
}
echo "ok\n";
