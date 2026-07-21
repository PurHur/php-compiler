<?php
set_error_handler(function ($n, $m) {
    if ($n === E_DEPRECATED) {
        echo "DEP\n";
        return true;
    }
    return false;
});

try {
    $r = basename(null);
    echo "basename OK " . var_export($r, true) . "\n";
} catch (Throwable $e) {
    echo "basename " . get_class($e) . ": " . $e->getMessage() . "\n";
}

try {
    $r = dirname(null);
    echo "dirname OK " . var_export($r, true) . "\n";
} catch (Throwable $e) {
    echo "dirname " . get_class($e) . ": " . $e->getMessage() . "\n";
}

try {
    $r = pathinfo(null);
    echo "pathinfo OK " . count($r) . "\n";
} catch (Throwable $e) {
    echo "pathinfo " . get_class($e) . ": " . $e->getMessage() . "\n";
}
