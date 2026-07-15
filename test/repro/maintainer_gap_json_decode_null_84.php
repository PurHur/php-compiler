<?php

declare(strict_types=1);

// #18852 — json_decode(null) TypeError on PHP_COMPILER_PROFILE=8.4 (ext/json/json.stub.php string $json).

putenv('PHP_COMPILER_PROFILE=8.4');

try {
    json_decode(null);
    fwrite(STDERR, "fail: uncaught\n");
    exit(1);
} catch (TypeError $e) {
    $msg = $e->getMessage();
    if (!str_contains($msg, 'json_decode(): Argument #1 ($json) must be of type string, null given')) {
        fwrite(STDERR, "fail: message={$msg}\n");
        exit(1);
    }
}

echo "ok\n";
