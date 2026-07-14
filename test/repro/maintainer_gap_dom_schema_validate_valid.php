<?php

declare(strict_types=1);

// Issue #18806 — DOMDocument::schemaValidate()/relaxNGValidate() valid on-disk schema (ext/dom/document.c).

$doc = new DOMDocument();
$doc->loadXML('<root/>');

$xsd = tempnam(sys_get_temp_dir(), 'xsd');
file_put_contents(
    $xsd,
    '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="root"/></xs:schema>'
);

$rng = tempnam(sys_get_temp_dir(), 'rng');
file_put_contents(
    $rng,
    '<?xml version="1.0"?><grammar xmlns="http://relaxng.org/ns/structure/1.0"><start><element name="root"><empty/></element></start></grammar>'
);

$schemaOk = $doc->schemaValidate($xsd);
$relaxOk = $doc->relaxNGValidate($rng);

unlink($xsd);
unlink($rng);

if (true !== $schemaOk || true !== $relaxOk) {
    fwrite(STDERR, 'fail: expected true for valid schema files schema='.var_export($schemaOk, true).' relax='.var_export($relaxOk, true)."\n");
    exit(1);
}

$doc2 = new DOMDocument();
$doc2->loadXML('<wrong/>');
$xsd2 = tempnam(sys_get_temp_dir(), 'xsd');
file_put_contents(
    $xsd2,
    '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="root"/></xs:schema>'
);

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});

$bad = $doc2->schemaValidate($xsd2);
restore_error_handler();
unlink($xsd2);

if (false !== $bad) {
    fwrite(STDERR, "fail: mismatched root should return false\n");
    exit(1);
}

$hasRootMismatch = false;
foreach ($warnings as $warning) {
    if (str_contains($warning, "Element 'wrong': No matching global declaration")) {
        $hasRootMismatch = true;
        break;
    }
}

if (!$hasRootMismatch) {
    fwrite(STDERR, "fail: missing root mismatch warning\n");
    exit(1);
}

echo "ok\n";
