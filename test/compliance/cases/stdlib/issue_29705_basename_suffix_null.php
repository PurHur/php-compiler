<?php
set_error_handler(function ($n, $m) {
    if ($n === E_DEPRECATED) {
        echo "DEP\n";
        return true;
    }
    return false;
});

try {
    $r = basename('/tmp/foo.txt', null);
    echo 'suffix null OK ' . var_export($r, true) . "\n";
} catch (Throwable $e) {
    echo 'suffix null ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}

try {
    $r = basename('/tmp/foo.txt', '.txt');
    echo 'suffix ok ' . var_export($r, true) . "\n";
} catch (Throwable $e) {
    echo 'suffix ok ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
