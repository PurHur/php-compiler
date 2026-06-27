<?php

declare(strict_types=1);

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});

session_name('');
$name = session_name();
if ($name !== 'PHPSESSID') {
    echo "fail: expected PHPSESSID after empty set, got {$name}\n";
    exit(1);
}

$warned = false;
foreach ($warnings as $message) {
    if (false !== strpos($message, 'cannot be numeric or empty')) {
        $warned = true;
        break;
    }
}
if (!$warned) {
    echo "fail: expected session_name() empty-string Warning\n";
    exit(1);
}

echo "ok\n";
