<?php
// Repro #20360 — function_exists/method_exists/property_exists(null) soft-null under PROFILE=8.4 (#21281)
set_error_handler(static function (int $no, string $msg): bool {
    return E_DEPRECATED === $no;
});
echo 'function_exists=', var_export(function_exists(null), true), "\n";
echo 'method_exists=', var_export(method_exists('stdClass', null), true), "\n";
echo 'property_exists=', var_export(property_exists('stdClass', null), true), "\n";
echo 'class_exists=', var_export(class_exists(null), true), "\n";
