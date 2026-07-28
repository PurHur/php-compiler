<?php
// #24207 — mb_str_split(null) under PROFILE=8.4: Zend DEP+coerce to '' → [] (not TypeError).
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }

    return true;
});
try {
    $r = mb_str_split(null);
    echo 'result=', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 1), "\n";
