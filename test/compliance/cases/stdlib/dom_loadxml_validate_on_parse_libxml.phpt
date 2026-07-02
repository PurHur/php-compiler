--TEST--
DOMDocument::loadXML() validateOnParse records libxml errors for undeclared elements (#14536, ext/dom/document.c)
--FILE--
<?php
libxml_use_internal_errors(true);
libxml_clear_errors();
$doc = new DOMDocument();
$doc->validateOnParse = true;
$xml = '<?xml version="1.0"?><!DOCTYPE root [<!ELEMENT root (#PCDATA)>]><undeclared/>';
@$doc->loadXML($xml);
$found = false;
foreach (libxml_get_errors() as $error) {
    if (str_contains($error->message, 'No declaration for element')) {
        $found = true;
        break;
    }
}
echo $found ? "libxml_ok\n" : "libxml_missing\n";
?>
--EXPECT--
libxml_ok
