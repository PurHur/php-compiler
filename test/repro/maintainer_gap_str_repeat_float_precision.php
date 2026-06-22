<?php
declare(strict_types=1);

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$result = str_repeat('x', 2.9);
restore_error_handler();
echo $result, "\n", 'warnings=', count($warnings), "\n";
if ($warnings) {
    echo $warnings[0], "\n";
}
