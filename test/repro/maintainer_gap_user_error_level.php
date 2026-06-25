<?php

declare(strict_types=1);

// Issue #11434 — user_error() second $error_type argument (ext/standard/basic_functions.c).
$errorType = E_USER_WARNING;
$level = null;
set_error_handler(static function (int $errno) use (&$level): bool {
    $level = $errno;

    return true;
});
user_error('probe', $errorType);
restore_error_handler();
echo (512 === $level) ? "level_ok\n" : "level_fail\n";
