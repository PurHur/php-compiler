<?php

declare(strict_types=1);

$doc = new DOMDocument();
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$ok = $doc->loadHTML('<p><unclosed');
restore_error_handler();

if (true !== $ok) {
    echo "fail: loadHTML returned false\n";
    exit(1);
}

$hasInvalid = false;
$hasUnclosed = false;
foreach ($warnings as $warning) {
    if (str_contains($warning, 'DOMDocument::loadHTML(): Tag unclosed invalid in Entity, line: 1')) {
        $hasInvalid = true;
    }
    if (str_contains($warning, "DOMDocument::loadHTML(): Couldn't find end of Start Tag unclosed in Entity, line: 1")) {
        $hasUnclosed = true;
    }
}

if (!$hasInvalid || !$hasUnclosed) {
    echo "fail: missing loadHTML unclosed-tag warnings\n";
    exit(1);
}

echo "ok\n";
