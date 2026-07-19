<?php
// Repro #21181 / formerly #20154 — bin2hex(null) DEP+coerce under PHP_COMPILER_PROFILE=8.4
error_reporting(E_ALL);
set_error_handler(static function (): bool {
    return true;
});
try {
    echo var_export(bin2hex(null), true), "\n";
} catch (TypeError $e) {
    echo 'TypeError', "\n";
}
