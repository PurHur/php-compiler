<?php
/** Repro #28333 — json_validate(null) soft-null DEP + false under PROFILE=8.4. */
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }
    return true;
});
try {
    var_export(json_validate(null));
    echo "\n";
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 1), "\n";
var_export(json_validate('{"a":1}'));
echo "\n";
var_export(json_validate('{'));
echo "\n";
