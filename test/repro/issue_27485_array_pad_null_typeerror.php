<?php
// #27485 — AOT array_pad(null, …) must TypeError (catchable), not abort exit 134.
try {
    var_export(array_pad(null, 3, 'x'));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$a = null;
try {
    var_export(array_pad($a, 2, 0));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo implode(',', array_pad([1], 3, 'x')), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
