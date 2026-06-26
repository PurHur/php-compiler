<?php

declare(strict_types=1);

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
echo 'x';
$ok = header('Y: z');
var_export(false === $ok);
echo "\n";
if (isset($warnings[0]) && false !== strpos($warnings[0], 'headers already sent by')) {
    echo "warn_ok\n";
} else {
    echo "warn_bad\n";
}
