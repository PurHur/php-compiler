<?php
// #27472 — AOT array_keys(null) must TypeError (catchable), not abort exit 134.
try {
    var_export(array_keys(null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
