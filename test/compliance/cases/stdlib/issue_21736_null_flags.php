<?php
set_error_handler(function ($n, $m) {
    if ($n === E_DEPRECATED) {
        echo "DEP\n";
        return true;
    }
    return false;
});

try {
    $r = pathinfo("/a/b.php", null);
    echo "pathinfo OK\n";
} catch (Throwable $e) {
    echo "pathinfo " . get_class($e) . ": " . $e->getMessage() . "\n";
}

try {
    $r = fnmatch("a*", "abc", null);
    echo "fnmatch OK: " . var_export($r, true) . "\n";
} catch (Throwable $e) {
    echo "fnmatch " . get_class($e) . ": " . $e->getMessage() . "\n";
}

try {
    $r = preg_match("/a/", "a", $m, null);
    echo "preg_match OK: $r\n";
} catch (Throwable $e) {
    echo "preg_match " . get_class($e) . ": " . $e->getMessage() . "\n";
}
