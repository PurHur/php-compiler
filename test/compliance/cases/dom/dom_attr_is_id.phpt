--TEST--
DOMAttr::isId() after setIdAttribute / setIdAttributeNS / setIdAttributeNode (#20129, ext/dom/attr.c)
--FILE--
<?php
$d = new DOMDocument();
$e = $d->createElement('x');
$d->appendChild($e);
$e->setAttribute('id', 'foo');
echo method_exists(DOMAttr::class, 'isId') ? "method_ok\n" : "method_missing\n";
$attr = $e->getAttributeNode('id');
echo $attr->isId() ? "before_set_true\n" : "before_set_false\n";
$e->setIdAttribute('id', true);
echo $attr->isId() ? "after_set_true\n" : "after_set_false\n";
$e->setIdAttribute('id', false);
echo $attr->isId() ? "after_clear_true\n" : "after_clear_false\n";
$e->setAttributeNS('http://example.com', 'ex:aid', 'bar');
$e->setIdAttributeNS('http://example.com', 'aid', true);
$a2 = $e->getAttributeNodeNS('http://example.com', 'aid');
echo $a2->isId() ? "ns_true\n" : "ns_false\n";
$e->setAttribute('id2', 'baz');
$a3 = $e->getAttributeNode('id2');
$e->setIdAttributeNode($a3, true);
echo $a3->isId() ? "node_true\n" : "node_false\n";
$e->setAttribute('class', 'c');
echo $e->getAttributeNode('class')->isId() ? "class_true\n" : "class_false\n";
--EXPECT--
method_ok
before_set_false
after_set_true
after_clear_false
ns_true
node_true
class_false
