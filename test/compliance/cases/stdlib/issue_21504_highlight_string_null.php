<?php
set_error_handler(function ($n, $m) {
    if ($n === E_DEPRECATED) {
        echo "DEP\n";
        return true;
    }
    return false;
});

try {
    $r = highlight_string(null, true);
    echo "highlight_string OK len=" . strlen($r) . "\n";
} catch (Throwable $e) {
    echo "highlight_string " . get_class($e) . ": " . $e->getMessage() . "\n";
}
