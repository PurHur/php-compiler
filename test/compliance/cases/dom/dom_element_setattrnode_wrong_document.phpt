--TEST--
DOMElement::setAttributeNode/NS foreign Attr throws Wrong Document Error (#22709)
--FILE--
<?php
$d1 = new DOMDocument();
$d1->loadXML('<r/>');
$d2 = new DOMDocument();
$d2->loadXML('<r a="1"/>');
$attr = $d2->documentElement->getAttributeNode('a');
try {
    $d1->documentElement->setAttributeNode($attr);
    echo 'NO_THROW has=' . ($d1->documentElement->hasAttribute('a') ? '1' : '0') . "\n";
} catch (DOMException $e) {
    echo 'code=' . $e->getCode() . ' msg=' . $e->getMessage() . "\n";
}

$d1b = new DOMDocument();
$d1b->loadXML('<r xmlns:p="urn:x"/>');
$d2b = new DOMDocument();
$d2b->loadXML('<r xmlns:p="urn:x" p:a="1"/>');
$attrNs = $d2b->documentElement->getAttributeNodeNS('urn:x', 'a');
try {
    $d1b->documentElement->setAttributeNodeNS($attrNs);
    echo 'NS NO_THROW has=' . ($d1b->documentElement->hasAttributeNS('urn:x', 'a') ? '1' : '0') . "\n";
} catch (DOMException $e) {
    echo 'NS code=' . $e->getCode() . ' msg=' . $e->getMessage() . "\n";
}
?>
--EXPECT--
code=4 msg=Wrong Document Error
NS code=4 msg=Wrong Document Error
