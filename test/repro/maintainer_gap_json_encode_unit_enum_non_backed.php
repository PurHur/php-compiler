<?php
/** Repro #22681 / #22688 — unit enum json_encode last-error vs Zend. */
enum U { case X; }

$r = json_encode(U::X);
var_export($r);
echo "\n";
echo json_last_error(), ':', json_last_error_msg(), "\n";

try {
    json_encode(U::X, JSON_THROW_ON_ERROR);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
