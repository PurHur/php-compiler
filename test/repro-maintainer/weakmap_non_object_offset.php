<?php

$wm = new WeakMap();
try {
    var_export($wm[1]);
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
try {
    var_export(isset($wm[1]));
} catch (Throwable $e) {
    echo 'isset: ', $e::class, ': ', $e->getMessage(), "\n";
}
