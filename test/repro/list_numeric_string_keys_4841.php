<?php
// Case A — single slot, string-key source
try {
    list($a) = ['k' => 1];
    echo "a=", var_export($a, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

// Case B — multiple slots, all-string-key source
try {
    list($a, $b) = ['x' => 1, 'y' => 2];
    echo "a=$a b=$b\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

// Case C — short [] destruct must still TypeError
try {
    [$a, $b] = ['x' => 1, 'y' => 2];
    echo "no error\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
