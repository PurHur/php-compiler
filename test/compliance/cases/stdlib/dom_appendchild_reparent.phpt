--TEST--
stdlib DOMNode::appendChild() reparent removes ghost child (#17556, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$root = $doc->documentElement;
$a = $root->firstChild;
$root->appendChild($a);
echo $root->childNodes->length, "\n";
echo $root->firstChild->nodeName, "\n";

$doc2 = new DOMDocument();
$root2 = $doc2->createElement('root');
$doc2->appendChild($root2);
$node = $doc2->createElement('x');
$root2->appendChild($node);
$frag = $doc2->createDocumentFragment();
$frag->appendChild($node);
echo var_export($root2->hasChildNodes(), true), "\n";
echo $frag->childNodes->length, "\n";
?>
--EXPECT--
2
b
false
1
