<?php
declare(strict_types=1);

foreach (['md5', 'sha1'] as $fn) {
    try {
        $fn(null);
        fwrite(STDERR, "fail: {$fn}(null) did not throw\n");
        exit(1);
    } catch (TypeError $e) {
        if (!str_contains($e->getMessage(), 'must be of type string, null given')) {
            fwrite(STDERR, "fail: {$fn} wrong message: {$e->getMessage()}\n");
            exit(1);
        }
    }
}
echo "ok\n";
