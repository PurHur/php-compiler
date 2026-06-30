<?php

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    if (E_DEPRECATED === $severity) {
        $warnings[] = $message;
        return true;
    }
    return false;
});
preg_match('/(l+)/', 'hello', $m, PREG_OFFSET_CAPTURE, 2.7);
echo $m[1][1], "\n";
preg_match_all('/(l+)/', 'hello', $all, PREG_OFFSET_CAPTURE, 2.7);
echo $all[1][0][1], "\n";
restore_error_handler();
echo 'warnings=', count($warnings), "\n";
