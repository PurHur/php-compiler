<?php

declare(strict_types=1);

$doc = new DOMDocument();
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$ok = $doc->loadXML('<unclosed');
restore_error_handler();

if (false !== $ok) {
    echo "fail: loadXML should return false\n";
    exit(1);
}

foreach ($warnings as $warning) {
    if (str_contains($warning, "DOMDocument::loadXML(): Couldn't find end of Start Tag unclosed line 1 in Entity, line: 1")) {
        echo "ok\n";
        exit(0);
    }
}

echo "fail: missing DOMDocument::loadXML() warning prefix\n";
exit(1);
