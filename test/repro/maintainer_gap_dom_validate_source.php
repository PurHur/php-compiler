<?php

declare(strict_types=1);

// Issue #18748 — DOMDocument::schemaValidateSource()/relaxNGValidateSource() (ext/dom/document.c).

$doc = new DOMDocument();
$doc->loadXML('<root/>');

foreach (['schemaValidateSource', 'relaxNGValidateSource'] as $method) {
    if (!method_exists($doc, $method)) {
        fwrite(STDERR, "fail: missing method {$method}\n");
        exit(1);
    }
}

try {
    $doc->schemaValidateSource('');
    fwrite(STDERR, "fail: empty schema source should ValueError\n");
    exit(1);
} catch (\ValueError $e) {
    if ('DOMDocument::schemaValidateSource(): Argument #1 ($source) must not be empty' !== $e->getMessage()) {
        fwrite(STDERR, 'fail: unexpected ValueError: '.$e->getMessage()."\n");
        exit(1);
    }
}

try {
    $doc->relaxNGValidateSource('');
    fwrite(STDERR, "fail: empty relaxNG source should ValueError\n");
    exit(1);
} catch (\ValueError $e) {
    if ('DOMDocument::relaxNGValidateSource(): Argument #1 ($source) must not be empty' !== $e->getMessage()) {
        fwrite(STDERR, 'fail: unexpected ValueError: '.$e->getMessage()."\n");
        exit(1);
    }
}

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});

$schema = $doc->schemaValidateSource('not xml');
$relax = $doc->relaxNGValidateSource('not rng');
$minimalXsd = $doc->schemaValidateSource(
    '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"></xs:schema>'
);
$minimalRng = $doc->relaxNGValidateSource(
    '<grammar xmlns="http://relaxng.org/ns/structure/1.0"></grammar>'
);

restore_error_handler();

if (false !== $schema || false !== $relax || false !== $minimalXsd || false !== $minimalRng) {
    fwrite(STDERR, "fail: validation methods should return false\n");
    exit(1);
}

$need = [
    'DOMDocument::schemaValidateSource(): Invalid Schema',
    'DOMDocument::relaxNGValidateSource(): Invalid RelaxNG',
    "Element 'root': No matching global declaration available for the validation root.",
    'grammar has no children',
];
foreach ($need as $fragment) {
    $found = false;
    foreach ($warnings as $warning) {
        if (str_contains($warning, $fragment)) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        fwrite(STDERR, "fail: missing warning fragment: {$fragment}\n");
        exit(1);
    }
}

echo "ok\n";
