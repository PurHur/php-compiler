<?php

$errs = [];
set_error_handler(static function (int $n, string $m) use (&$errs): bool {
    $errs[] = $m;

    return true;
});
$r = ini_get_all(false);
echo ($r === false) ? "false_ok\n" : "false_fail\n";
echo isset($errs[0]) && str_contains($errs[0], 'Extension "" cannot be found') ? "warn_ok\n" : "warn_fail\n";
