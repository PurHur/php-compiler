<?php

declare(strict_types=1);

// #18533 — hash() named parameter options: (ext/hash/hash.stub.php PHP 8.2)
$digest = hash('sha256', 'data', options: []);
$expected = '3a6eb0790f39ac87c94f3856b2dd2c5d110e6811602261a9a923d3bb23adc8b7';
if ($digest !== $expected) {
    fwrite(STDERR, "expected {$expected}, got {$digest}\n");
    exit(1);
}
echo "ok\n";
