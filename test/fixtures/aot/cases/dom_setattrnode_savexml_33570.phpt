--TEST--
AOT: setAttributeNode after createAttribute+value — saveXML keeps attrs (#33570)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$e = $d->createElement('r');
$a = $d->createAttribute('id');
$a->value = 'x';
$e->setAttributeNode($a);
$d->appendChild($e);
echo $d->saveXML($e), "\n";
--EXPECT--
<r id="x"/>
