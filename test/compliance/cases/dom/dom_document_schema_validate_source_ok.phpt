--TEST--
DOMDocument::schemaValidateSource() — valid in-memory XSD returns true; invalid fills libxml_get_errors (#19419, #20181, ext/dom/document.c)
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
echo count($warnings), "\n";
$errs = libxml_get_errors();
echo count($errs), "\n";
if (isset($errs[0])) {
    echo trim($errs[0]->message), "\n";
    echo (int) $errs[0]->level, "\n";
}
?>
--EXPECT--
true
0
0
false
0
1
Element 'x': No matching global declaration available for the validation root.
2
