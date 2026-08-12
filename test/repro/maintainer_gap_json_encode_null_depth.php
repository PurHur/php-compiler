<?php
declare(strict_types=1);
// #30486 — json_encode(..., null $depth) must TypeError (Z_PARAM_LONG).
foreach ([0, JSON_THROW_ON_ERROR] as $flags) {
    try {
        $r = json_encode([], $flags, null);
        echo "flags=$flags => ", var_export($r, true), " err=", json_last_error_msg(), "\n";
    } catch (Throwable $e) {
        echo "flags=$flags => ", get_class($e), ": ", $e->getMessage(), "\n";
    }
}
