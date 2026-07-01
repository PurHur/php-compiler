--TEST--
dom DOMImplementation::createDocument factory without host DOM (#6140)
--FILE--
<?php
echo (int) class_exists('DOMImplementation'), "\n";
$impl = new DOMImplementation();
$doctype = $impl->createDocumentType('html', '-//W3C//DTD HTML 4.01//EN', 'http://www.w3.org/TR/html4/strict.dtd');
$doc = $impl->createDocument(null, 'html', $doctype);
$doc->formatOutput = true;
echo $doc->saveXML();
echo (int) $impl->hasFeature('XML', '1.0'), "\n";
echo (int) $impl->hasFeature('XML', '2.0'), "\n";
echo (int) $impl->hasFeature('HTML', '2.0'), "\n";
echo (int) $impl->hasFeature('XBL', '2.0'), "\n";
--EXPECT--
1
<?xml version="1.0"?>
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html/>
1
1
0
0
