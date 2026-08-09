<?php
/**
 * Repro #29241 — PROFILE≥8.2 $obj->x++ must E_DEPRECATED + E_WARNING Undefined property.
 */
class U {}
$errs = [];
set_error_handler(static function (int $errno, string $errstr) use (&$errs): bool {
    $errs[] = [$errno, $errstr];
    return true;
});
$u = new U();
$u->x++;
restore_error_handler();
foreach ($errs as $e) {
    echo "err[{$e[0]}] {$e[1]}\n";
}
echo "x=", $u->x, "\n";
