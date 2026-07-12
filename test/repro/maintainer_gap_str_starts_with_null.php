<?php

foreach (['str_starts_with', 'str_ends_with', 'str_contains'] as $fn) {
    $result = $fn(null, 'a');
    if (false !== $result) {
        fwrite(STDERR, "$fn(null, 'a'): expected false, got " . var_export($result, true) . "\n");
        exit(1);
    }
    echo "$fn: ok\n";
}
