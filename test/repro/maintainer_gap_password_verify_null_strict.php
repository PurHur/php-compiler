<?php

declare(strict_types=1);

// Maintainer gap #17051 — password_verify(null, …) under strict_types must TypeError.
$hash = password_hash('secret', PASSWORD_BCRYPT);
try {
    password_verify(null, $hash);
    fwrite(STDERR, "fail: expected TypeError\n");
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
