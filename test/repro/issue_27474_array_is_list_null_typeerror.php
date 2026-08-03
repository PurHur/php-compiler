<?php
// #27474 — AOT array_is_list(null) must TypeError (catchable), not abort exit 134.
try {
    var_export(array_is_list(null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
