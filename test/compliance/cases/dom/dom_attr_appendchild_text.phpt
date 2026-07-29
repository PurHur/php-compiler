--TEST--
DOMAttr::appendChild/insertBefore accept Text and EntityReference (#24512)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r/>');
$a = $d->createAttribute('x');
$a->appendChild($d->createTextNode('hel'));
$a->appendChild($d->createTextNode('lo'));
echo 'value=', $a->value, "\n";
echo 'len=', $a->childNodes->length, "\n";
$d->documentElement->setAttributeNode($a);
echo 'getAttribute=', $d->documentElement->getAttribute('x'), "\n";

$a2 = $d->createAttribute('y');
$t1 = $d->createTextNode('b');
$a2->appendChild($t1);
$a2->insertBefore($d->createTextNode('a'), $t1);
echo 'insertBefore=', $a2->value, "\n";

try {
    $a->appendChild($d->createCDATASection('cd'));
    echo "cdata_unexpected_ok\n";
} catch (Throwable $e) {
    echo 'cdata=', get_class($e), ' code=', $e->getCode(), "\n";
}

$a3 = $d->createAttribute('z');
$a3->appendChild($d->createTextNode('a'));
$a3->appendChild($d->createEntityReference('amp'));
$a3->appendChild($d->createTextNode('b'));
echo 'entity_value=', $a3->value, "\n";
$d->documentElement->setAttributeNode($a3);
echo 'entity_ga=', $d->documentElement->getAttribute('z'), "\n";
echo 'entity_xml=', $d->saveXML($d->documentElement), "\n";

$a4 = $d->createAttribute('w');
$old = $d->createTextNode('old');
$a4->appendChild($old);
$a4->replaceChild($d->createTextNode('new'), $old);
echo 'replace=', $a4->value, "\n";
$a4->removeChild($a4->firstChild);
echo 'remove_empty=', var_export($a4->value, true), ' len=', $a4->childNodes->length, "\n";
--EXPECT--
value=hello
len=2
getAttribute=hello
insertBefore=ab
cdata=DOMException code=3
entity_value=ab
entity_ga=ab
entity_xml=<r x="hello" z="a&amp;b"/>
replace=new
remove_empty='' len=0
