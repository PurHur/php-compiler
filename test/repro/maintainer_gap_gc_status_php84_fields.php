<?php

declare(strict_types=1);

$s = gc_status();
foreach (['running', 'protected', 'full', 'buffer_size'] as $key) {
    if (!array_key_exists($key, $s)) {
        file_put_contents('php://stderr', "missing key: {$key}\n");
        exit(1);
    }
}
echo "ok\n";
