<?php
// Repro #21196 — substr_count/substr_replace soft-null under PROFILE=8.4

error_reporting(E_ALL);
$dep = 0;
set_error_handler(static function (int $no) use (&$dep): bool {
    if (E_DEPRECATED === $no) {
        ++$dep;
    }

    return true;
});

$c = substr_count(null, 'a');
$r = substr_replace(null, 'x', 0);
restore_error_handler();

echo 'substr_count=', $c, ' dep=', $dep, ' replace=', $r, "\n";
