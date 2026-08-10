<?php

declare(strict_types=1);

// #29867 — base64_decode(..., null) $strict under strict_types → TypeError (ext/standard/base64.c).
try {
    echo base64_decode('YQ==', null), "\n";
    echo "fail:uncaught\n";
} catch (TypeError $e) {
    echo 'ok:', $e->getMessage(), "\n";
}
