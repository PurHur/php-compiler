<?php

$wm = new WeakMap();
try {
    $wm[null] = 1;
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
try {
    var_export($wm[null]);
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
try {
    var_export(isset($wm[null]));
} catch (Throwable $e) {
    echo 'isset: ', $e::class, ': ', $e->getMessage(), "\n";
}
