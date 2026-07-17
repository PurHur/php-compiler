--TEST--
DOMImplementation::createDocumentType() optional publicId/systemId (#19797)
--FILE--
<?php
$impl = new DOMImplementation();

$dt1 = $impl->createDocumentType('html');
echo $dt1->name, '|', $dt1->publicId === '' ? "''" : $dt1->publicId, '|', $dt1->systemId === '' ? "''" : $dt1->systemId, "\n";

$dt2 = $impl->createDocumentType('html', '-//W3C//DTD HTML 4.01//EN');
echo $dt2->name, '|', $dt2->publicId, '|', $dt2->systemId === '' ? "''" : $dt2->systemId, "\n";

$dt3 = $impl->createDocumentType(
    'html',
    '-//W3C//DTD HTML 4.01//EN',
    'http://www.w3.org/TR/html4/strict.dtd'
);
echo $dt3->name, '|', $dt3->publicId, '|', $dt3->systemId, "\n";

try {
    $impl->createDocumentType();
} catch (ArgumentCountError $e) {
    echo 'zero:', $e->getMessage(), "\n";
}

try {
    $impl->createDocumentType('a', 'b', 'c', 'd');
} catch (ArgumentCountError $e) {
    echo 'four:', $e->getMessage(), "\n";
}
--EXPECT--
html|''|''
html|-//W3C//DTD HTML 4.01//EN|''
html|-//W3C//DTD HTML 4.01//EN|http://www.w3.org/TR/html4/strict.dtd
zero:DOMImplementation::createDocumentType() expects at least 1 argument, 0 given
four:DOMImplementation::createDocumentType() expects at most 3 arguments, 4 given
