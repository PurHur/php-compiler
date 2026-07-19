--TEST--
dom importNode HTML id into XML document — getElementById (#20830, re-#19212, ext/dom/node.c)
--FILE--
<?php
$src = new DOMDocument();
$src->loadHTML('<html><body><div id="x">z</div></body></html>');
$el = $src->getElementById('x');
$dst = new DOMDocument('1.0', 'UTF-8');
$dst->appendChild($dst->createElement('root'));
$n = $dst->importNode($el, true);
echo 'attr:', $n->getAttribute('id'), "\n";
echo 'isId:', $n->getAttributeNode('id')->isId() ? 'Y' : 'N', "\n";
$dst->documentElement->appendChild($n);
$found = $dst->getElementById('x');
echo null !== $found && 'z' === $found->textContent ? 'ok' : 'null', "\n";

// setIdAttribute does not survive importNode (Zend).
$src2 = new DOMDocument();
$src2->loadXML('<root><div xid="y">w</div></root>');
$el2 = $src2->getElementsByTagName('div')->item(0);
$el2->setIdAttribute('xid', true);
$dst2 = new DOMDocument('1.0', 'UTF-8');
$dst2->appendChild($dst2->createElement('root'));
$n2 = $dst2->importNode($el2, true);
$dst2->documentElement->appendChild($n2);
echo null === $dst2->getElementById('y') ? 'setid_ok' : 'setid_leak', "\n";
--EXPECT--
attr:x
isId:Y
ok
setid_ok
