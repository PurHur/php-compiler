--TEST--
DOMDocument::schemaValidate()/relaxNGValidate() warn with method prefix and return false (#15691, #25843, ext/dom/document.c)
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
$schema = $doc->schemaValidate('nonexistent.xsd');
$relax = $doc->relaxNGValidate('nonexistent.rng');
restore_error_handler();
echo ($schema ? 'schema-true' : 'schema-false'), "\n";
echo ($relax ? 'relax-true' : 'relax-false'), "\n";
echo count($warnings), "\n";
foreach ($warnings as $w) {
    echo $w, "\n";
}
--EXPECTF--
schema-false
relax-false
6
DOMDocument::schemaValidate(): I/O warning : failed to load external entity "%s/nonexistent.xsd"
DOMDocument::schemaValidate(): Failed to locate the main schema resource at '%s/nonexistent.xsd'.
DOMDocument::schemaValidate(): Invalid Schema
DOMDocument::relaxNGValidate(): I/O warning : failed to load external entity "%s/nonexistent.rng"
DOMDocument::relaxNGValidate(): xmlRelaxNGParse: could not load %s/nonexistent.rng
DOMDocument::relaxNGValidate(): Invalid RelaxNG
