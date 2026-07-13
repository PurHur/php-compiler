--TEST--
DOMDocument::schemaValidateSource()/relaxNGValidateSource() — in-memory validation surface (#18748, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root/>');
echo method_exists($doc, 'schemaValidateSource') ? 'schema-source ' : 'missing ';
echo method_exists($doc, 'relaxNGValidateSource') ? 'relaxng-source ' : 'missing ';
echo "\n";
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$schema = $doc->schemaValidateSource('not xml');
$relax = $doc->relaxNGValidateSource('<grammar xmlns="http://relaxng.org/ns/structure/1.0"></grammar>');
restore_error_handler();
echo ($schema ? 'schema-true' : 'schema-false'), "\n";
echo ($relax ? 'relax-true' : 'relax-false'), "\n";
echo count($warnings), "\n";
foreach ($warnings as $w) {
    echo $w, "\n";
}
?>
--EXPECTF--
schema-source relaxng-source
schema-false
relax-false
%d
DOMDocument::schemaValidateSource(): Entity: line 1: parser error : Start tag expected, '<' not found
DOMDocument::schemaValidateSource(): not xml
DOMDocument::schemaValidateSource(): ^
DOMDocument::schemaValidateSource(): Failed to parse the XML resource 'in_memory_buffer'.
DOMDocument::schemaValidateSource(): Invalid Schema
DOMDocument::relaxNGValidateSource(): grammar has no children
DOMDocument::relaxNGValidateSource(): Element <grammar> has no <start>
DOMDocument::relaxNGValidateSource(): Invalid RelaxNG
