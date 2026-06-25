<?php

declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('curl'), "\n";
echo 'funcs=', (int) function_exists('curl_init'), "\n";

try {
    curl_init();
    echo "curl_init=ok\n";
} catch (Throwable $e) {
    echo 'curl_init=', get_class($e), "\n";
}
