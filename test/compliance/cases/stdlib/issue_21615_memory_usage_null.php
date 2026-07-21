<?php
set_error_handler(function ($n, $m) {
    if ($n === E_DEPRECATED) {
        echo "DEP\n";
        return true;
    }
    return false;
});

try {
    $r = memory_get_usage(null);
    echo "memory_get_usage OK\n";
} catch (Throwable $e) {
    echo "memory_get_usage " . get_class($e) . ": " . $e->getMessage() . "\n";
}

try {
    $r = memory_get_peak_usage(null);
    echo "memory_get_peak_usage OK\n";
} catch (Throwable $e) {
    echo "memory_get_peak_usage " . get_class($e) . ": " . $e->getMessage() . "\n";
}
