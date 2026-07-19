--TEST--
Dom\Entity / EntityReference / Notation + Dom\DOMException (#20983)
--SKIPIF--
<?php
if (!class_exists('Dom\\XMLDocument')) {
    die('skip Dom\\XMLDocument requires PHP_COMPILER_PROFILE=8.4 (#20983)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
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
?>
--EXPECT--
Dom\Entity=yes
Dom\EntityReference=yes
Dom\Notation=yes
Dom\DOMException=yes
createEntityReference=Dom\EntityReference
er_node=yes
er_eref=yes
legacyEntityReference=DOMEntityReference
dtd_entity=Dom\Entity
dtd_notation=Dom\Notation
dtd_child=Dom\EntityReference
catch_dom=yes
