<?php

declare(strict_types=1);

// Maintainer gap #17049 — microtime(null) under strict_types must TypeError (ext/standard/microtime.c).
try {
    microtime(null);
    fwrite(STDERR, "fail: expected TypeError\n");
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
