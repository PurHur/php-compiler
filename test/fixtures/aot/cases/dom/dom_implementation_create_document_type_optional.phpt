--TEST--
AOT: DOMImplementation::createDocumentType() 1-arg optional publicId/systemId (#19797)
--FILE--
<?php
declare(strict_types=1);
$i = new DOMImplementation();
$dt = $i->createDocumentType('html');
echo $dt->name, '|', $dt->publicId === '' ? "''" : $dt->publicId, '|', $dt->systemId === '' ? "''" : $dt->systemId, "\n";
$dt2 = $i->createDocumentType('html', '-//W3C//DTD HTML 4.01//EN', 'http://www.w3.org/TR/html4/strict.dtd');
echo $dt2->name, '|', $dt2->publicId, '|', $dt2->systemId, "\n";
--EXPECT--
html|''|''
html|-//W3C//DTD HTML 4.01//EN|http://www.w3.org/TR/html4/strict.dtd
