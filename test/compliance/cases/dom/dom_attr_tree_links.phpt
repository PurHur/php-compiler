--TEST--
DOMAttr parentNode/siblings/value text child (#20501)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r a="1" b="2"/>');
$el = $doc->documentElement;
$a = $el->getAttributeNode('a');
$b = $el->getAttributeNode('b');
echo 'parent=', ($a->parentNode === $el) ? '1' : '0', "\n";
echo 'next=', ($a->nextSibling === $b) ? '1' : '0', "\n";
echo 'prev=', ($b->previousSibling === $a) ? '1' : '0', "\n";
echo 'first=', get_class($a->firstChild), ':', $a->firstChild->nodeValue, "\n";
echo 'has=', $a->hasChildNodes() ? '1' : '0', ' len=', $a->childNodes->length, "\n";
$o = $doc->createAttribute('x');
echo 'orphan_has=', $o->hasChildNodes() ? '1' : '0', "\n";
$o->value = 'hi';
echo 'orphan_first=', $o->firstChild->nodeValue, "\n";
$el->setAttributeNode($o);
echo 'attached=', ($o->parentNode === $el) ? '1' : '0', "\n";
$el->removeAttributeNode($o);
echo 'detached_parent=', ($o->parentNode === null) ? 'null' : 'set', ' first=', $o->firstChild->nodeValue, "\n";
?>
--EXPECT--
parent=1
next=1
prev=1
first=DOMText:1
has=1 len=1
orphan_has=0
orphan_first=hi
attached=1
detached_parent=null first=hi
