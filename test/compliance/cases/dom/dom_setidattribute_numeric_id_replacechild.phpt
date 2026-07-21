--TEST--
dom setIdAttribute numeric id then replaceChild — ID map sync (#21644, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a id="1"><b id="2">t</b><c/></a></root>');
$a = $doc->documentElement->firstChild;
$b = $a->firstChild;
$a->setIdAttribute('id', true);
$b->setIdAttribute('id', true);
echo null !== $doc->getElementById('1') ? 'id1:ok' : 'id1:null', "\n";
echo null !== $doc->getElementById('2') ? 'id2:ok' : 'id2:null', "\n";
$list = $a->childNodes;
$old = $a->firstChild;
$neu = $doc->createElement('x');
$a->replaceChild($neu, $old);
echo 'len=', $list->length, ' item0=', $list->item(0)->nodeName, "\n";
--EXPECT--
id1:ok
id2:ok
len=2 item0=x
