<?php

declare(strict_types=1);

$s = gc_status();
if (!array_key_exists('roots', $s)) {
    echo "skip — PHP 8.4 gc_status schema has no roots key\n";
    exit(0);
}
if (0 !== $s['roots']) {
    file_put_contents('php://stderr', 'expected roots=0 at cold start, got '.$s['roots']."\n");
    exit(1);
}
echo "ok roots={$s['roots']}\n";
