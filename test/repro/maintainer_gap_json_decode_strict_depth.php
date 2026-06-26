<?php

declare(strict_types=1);

try {
    json_decode('[]', true, '512');
    fwrite(STDERR, "fail: expected TypeError for string depth under strict_types\n");
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'json_decode(): Argument #3 ($depth) must be of type int, string given')) {
        fwrite(STDERR, 'unexpected depth message: '.$e->getMessage()."\n");
        exit(1);
    }
}

try {
    json_decode('[]', true, 512, '1');
    fwrite(STDERR, "fail: expected TypeError for string flags under strict_types\n");
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'json_decode(): Argument #4 ($flags) must be of type int, string given')) {
        fwrite(STDERR, 'unexpected flags message: '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
