<?php

foreach (['class_exists', 'extension_loaded', 'function_exists'] as $fn) {
    $result = $fn(null);
    if (false !== $result) {
        fwrite(STDERR, "$fn(null): expected false, got " . var_export($result, true) . "\n");
        exit(1);
    }
    echo "$fn: ok\n";
}
