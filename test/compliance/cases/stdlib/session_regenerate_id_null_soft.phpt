--TEST--
session_regenerate_id(null) soft-null DEP + no-session warning (#31444)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    $label = match ($errno) {
        E_DEPRECATED => 'DEPRECATED',
        E_WARNING => 'WARNING',
        default => (string) $errno,
    };
    echo $label, ': ', $errstr, "\n";

    return true;
});
var_export(session_regenerate_id(null));
echo "\n";
--EXPECT--
DEPRECATED: session_regenerate_id(): Passing null to parameter #1 ($delete_old_session) of type bool is deprecated
WARNING: session_regenerate_id(): Session ID cannot be regenerated when there is no active session
false
