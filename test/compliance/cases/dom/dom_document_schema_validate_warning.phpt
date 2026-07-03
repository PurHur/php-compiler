--TEST--
DOMDocument::schemaValidate()/relaxNGValidate() warn and return false (#15691, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root/>');
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$schema = $doc->schemaValidate('/nonexistent.xsd');
$relax = $doc->relaxNGValidate('/nonexistent.rng');
restore_error_handler();
echo ($schema ? 'schema-true' : 'schema-false'), "\n";
echo ($relax ? 'relax-true' : 'relax-false'), "\n";
echo count(array_filter($warnings, static fn (string $w): bool => str_contains($w, 'schemaValidate'))), "\n";
echo count(array_filter($warnings, static fn (string $w): bool => str_contains($w, 'relaxNGValidate'))), "\n";
--EXPECT--
schema-false
relax-false
1
1
