<?php

declare(strict_types=1);

if (!function_exists('session_cache_expire')) {
    fwrite(STDERR, "fail: session_cache_expire undefined\n");
    exit(1);
}

$expire = session_cache_expire();
if (180 !== $expire) {
    fwrite(STDERR, "fail: default expire={$expire} expected 180\n");
    exit(1);
}

session_cache_expire(240);
if (240 !== session_cache_expire()) {
    fwrite(STDERR, "fail: setter round-trip\n");
    exit(1);
}

try {
    session_cache_expire(0);
    fwrite(STDERR, "fail: zero should throw\n");
    exit(1);
} catch (ValueError) {
    // expected
}

echo "OK expire={$expire}\n";
