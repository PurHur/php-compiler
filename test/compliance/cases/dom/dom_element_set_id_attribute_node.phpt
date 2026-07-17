--TEST--
DOM DOMElement::setIdAttributeNode() enables getElementById (#20123, ext/dom/element.c)
--FILE--
<?php
$d = new DOMDocument();
$e = $d->createElement('x');
$d->appendChild($e);
$attr = $d->createAttribute('id');
$attr->value = 'foo';
$e->setAttributeNode($attr);
echo method_exists(DOMElement::class, 'setIdAttributeNode') ? "exists\n" : "missing\n";
$e->setIdAttributeNode($attr, true);
echo $d->getElementById('foo') ? "ok\n" : "null\n";
$e->setIdAttributeNode($attr, false);
echo null === $d->getElementById('foo') ? "cleared\n" : "still\n";
--EXPECT--
exists
ok
cleared
