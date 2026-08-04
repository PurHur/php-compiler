<?php
// #27510 — AOT usort(null) must TypeError (catchable), not silent true NO_THROW.
$a = null;
try {
    var_export(usort($a, fn($x, $y) => $x <=> $y));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
