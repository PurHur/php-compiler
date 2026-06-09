<?php
$impl = new DOMImplementation();
$doctype = $impl->createDocumentType('html', '-//W3C//DTD HTML 4.01//EN', 'http://www.w3.org/TR/html4/strict.dtd');
$doc = $impl->createDocument(null, 'html', $doctype);
$doc->formatOutput = false;
echo "no-format:", bin2hex($doc->saveXML()), "\n";
$doc->formatOutput = true;
echo "format:", bin2hex($doc->saveXML()), "\n";
