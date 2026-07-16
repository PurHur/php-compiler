--TEST--
DOMDocument::schemaValidateSource() — valid in-memory XSD returns true (#19419, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<r/>');
$xsd = '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="r"/></xs:schema>';
libxml_use_internal_errors(true);
libxml_clear_errors();
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$ok = $doc->schemaValidateSource($xsd);
restore_error_handler();
var_export($ok);
echo "\n";
echo count(libxml_get_errors()), "\n";
echo count($warnings), "\n";

$badDoc = new DOMDocument();
$badDoc->loadXML('<x/>');
libxml_clear_errors();
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$bad = $badDoc->schemaValidateSource($xsd);
restore_error_handler();
var_export($bad);
echo "\n";
echo count($warnings) > 0 ? 'warned' : 'silent';
echo "\n";
?>
--EXPECT--
true
0
0
false
warned
