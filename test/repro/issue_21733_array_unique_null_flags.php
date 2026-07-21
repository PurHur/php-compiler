<?php
set_error_handler(function ($n, $m) {
    if ($n === E_DEPRECATED) {
        echo "DEP\n";
        return true;
    }
    return false;
});

// null flags → deprecate + coerce to 0 (SORT_REGULAR), then dedupe
try {
    $r = array_unique([1, 1, 2], null);
    echo "array_unique OK " . count($r) . "\n";
} catch (Throwable $e) {
    echo "array_unique " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// control: default flags (no null)
try {
    $r = array_unique([1, 1, 2]);
    echo "default OK " . count($r) . "\n";
} catch (Throwable $e) {
    echo "default " . get_class($e) . "\n";
}
