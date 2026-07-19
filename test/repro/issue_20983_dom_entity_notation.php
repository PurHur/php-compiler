<?php

declare(strict_types=1);

foreach ([
    'Dom\\Entity',
    'Dom\\EntityReference',
    'Dom\\Notation',
    'Dom\\DOMException',
] as $c) {
    echo $c, '=', class_exists($c) ? 'yes' : 'no', "\n";
}

$xml = Dom\XMLDocument::createEmpty();
$er = $xml->createEntityReference('amp');
echo 'createEntityReference=', get_class($er), "\n";
echo 'er_node=', ($er instanceof Dom\Node) ? 'yes' : 'no', "\n";
echo 'er_eref=', ($er instanceof Dom\EntityReference) ? 'yes' : 'no', "\n";

$legacy = new DOMDocument();
echo 'legacyEntityReference=', get_class($legacy->createEntityReference('amp')), "\n";

$dtd = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE root [
  <!ENTITY foo "bar">
  <!NOTATION n PUBLIC "pub" "sys">
]>
<root>&foo;</root>
XML;
$doc = Dom\XMLDocument::createFromString($dtd);
$ent = $doc->doctype->entities->getNamedItem('foo');
$not = $doc->doctype->notations->getNamedItem('n');
echo 'dtd_entity=', $ent ? get_class($ent) : 'null', "\n";
echo 'dtd_notation=', $not ? get_class($not) : 'null', "\n";
echo 'dtd_child=', get_class($doc->documentElement->firstChild), "\n";

try {
    throw new Dom\DOMException('x');
} catch (Dom\DOMException $e) {
    echo 'catch_dom=', ($e instanceof DOMException) ? 'yes' : 'no', "\n";
}
