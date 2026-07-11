--TEST--
dom DOMDocumentType properties after createDocumentType (#14355)
--FILE--
<?php
$impl = new DOMImplementation();
$dt = $impl->createDocumentType(
    'html',
    '-//W3C//DTD HTML 4.01//EN',
    'http://www.w3.org/TR/html4/strict.dtd'
);
echo $dt->nodeName, '|', $dt->name, '|', $dt->publicId, '|', $dt->systemId, "\n";
--EXPECT--
html|html|-//W3C//DTD HTML 4.01//EN|http://www.w3.org/TR/html4/strict.dtd
