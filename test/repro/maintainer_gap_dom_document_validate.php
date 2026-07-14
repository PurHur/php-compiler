<?php

declare(strict_types=1);

// Issue #18833 — DOMDocument::validate() DTD validation (ext/dom/document.c).

function dom_validate(string $label, string $xml): bool
{
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    $result = $doc->validate();
    restore_error_handler();

    echo $label . ':validate=' . ($result ? 'true' : 'false') . "\n";
    foreach ($warnings as $warning) {
        echo $label . ':warning:' . $warning . "\n";
    }

    return $result;
}

if (!method_exists('DOMDocument', 'validate')) {
    fwrite(STDERR, "fail: DOMDocument::validate() missing\n");
    exit(1);
}

$noDtd = dom_validate('no-dtd', '<root/>');
$valid = dom_validate('valid-dtd', '<!DOCTYPE root [<!ELEMENT root (#PCDATA)>]><root>ok</root>');
$invalid = dom_validate('invalid-dtd', '<!DOCTYPE root [<!ELEMENT root EMPTY>]><root>text</root>');

if ($noDtd || !$valid || $invalid) {
    fwrite(STDERR, "fail: unexpected validate results noDtd=" . var_export($noDtd, true)
        . ' valid=' . var_export($valid, true)
        . ' invalid=' . var_export($invalid, true) . "\n");
    exit(1);
}

echo "ok\n";
