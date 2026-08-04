<?php
// #27511 — AOT array_multisort(null) must TypeError (catchable), not silent true NO_THROW.
$a = null;
try {
    var_export(array_multisort($a));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
