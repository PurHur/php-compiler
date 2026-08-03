<?php
// #27446 — AOT count(null) must TypeError (catchable), not return 0.
try {
    var_export(count(null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
