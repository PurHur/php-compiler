<?php
function try_read() {
    $x = null;
    echo $x[0];
}
function try_write() {
    $y = null;
    $y['k'] = 1;
}
foreach (['read' => 'try_read', 'write' => 'try_write'] as $label => $fn) {
    try {
        $fn();
        echo "$label: no_error\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
