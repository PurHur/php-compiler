<?php
// #27482 — AOT array_pop(null) must TypeError (catchable), not abort exit 134.
$a = null;
try {
    var_export(array_pop($a));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
