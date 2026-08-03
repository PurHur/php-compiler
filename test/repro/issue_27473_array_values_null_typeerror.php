<?php
// #27473 — AOT array_values(null) must TypeError (catchable), not abort exit 134.
try {
    var_export(array_values(null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
