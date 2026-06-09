<?php
$impl = new DOMImplementation();
$doctype = $impl->createDocumentType('html', '-//W3C//DTD HTML 4.01//EN', 'http://www.w3.org/TR/html4/strict.dtd');
$doc = $impl->createDocument(null, 'html', $doctype);
$doc->formatOutput = true;
echo $doc->saveXML();
echo "hasFeature XML 2.0: ", (int) $impl->hasFeature('XML', '2.0'), "\n";
echo "hasFeature HTML 2.0: ", (int) $impl->hasFeature('HTML', '2.0'), "\n";
echo "hasFeature XBL 2.0: ", (int) $impl->hasFeature('XBL', '2.0'), "\n";
