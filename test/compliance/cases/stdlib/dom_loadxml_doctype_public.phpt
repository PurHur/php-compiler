--TEST--
Stdlib: DOMDocument::loadXML() PUBLIC doctype exposes DOMDocumentType (#15292, ext/dom/document.c)
--FILE--
<?php
$d = new DOMDocument();
$xml = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd"><html/>';
$d->loadXML($xml);
echo null === $d->doctype ? "no_doctype\n" : "has_doctype\n";
echo $d->doctype->name, "\n";
echo $d->doctype->publicId, "\n";
?>
--EXPECT--
has_doctype
html
-//W3C//DTD XHTML 1.0 Strict//EN
