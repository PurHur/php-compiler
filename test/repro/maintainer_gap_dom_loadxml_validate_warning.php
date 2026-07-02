<?php

declare(strict_types=1);

libxml_use_internal_errors(false);
libxml_clear_errors();

$doc = new DOMDocument();
$doc->validateOnParse = true;
$xml = '<?xml version="1.0"?><!DOCTYPE root [<!ELEMENT root (#PCDATA)>]><undeclared/>';

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
@$doc->loadXML($xml);
restore_error_handler();

$hasWarning = false;
foreach ($warnings as $warning) {
    if (str_contains($warning, 'DOMDocument::loadXML()')
        && str_contains($warning, 'No declaration for element')) {
        $hasWarning = true;
        break;
    }
}

if (!$hasWarning) {
    fwrite(STDERR, "fail: expected PHP Warning from loadXML validateOnParse\n");
    exit(1);
}

echo "ok\n";
