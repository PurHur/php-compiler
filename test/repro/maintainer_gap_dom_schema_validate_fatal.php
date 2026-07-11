<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root/>');

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});

$result = $doc->schemaValidate('/nonexistent.xsd');
$rng = $doc->relaxNGValidate('/nonexistent.rng');

restore_error_handler();

if (false !== $result || false !== $rng) {
    echo 'fail: expected false return values'."\n";
    exit(1);
}

$hasSchemaWarning = false;
$hasRelaxWarning = false;
foreach ($warnings as $warning) {
    if (str_contains($warning, 'DOMDocument::schemaValidate()')) {
        $hasSchemaWarning = true;
    }
    if (str_contains($warning, 'DOMDocument::relaxNGValidate()')) {
        $hasRelaxWarning = true;
    }
}

if (!$hasSchemaWarning || !$hasRelaxWarning) {
    echo "fail: missing DOM validation warnings\n";
    exit(1);
}

echo "ok\n";
