<?php

declare(strict_types=1);

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
echo 'x';
$r = header_remove();
if (false === $r) {
    echo "ok\n";
} else {
    echo 'fail: header_remove() return=', var_export($r, true), ' type=', gettype($r), "\n";
}
if (isset($warnings[0]) && false !== strpos($warnings[0], 'headers already sent by')) {
    echo "warn_ok\n";
} else {
    echo "warn_bad\n";
}
