--TEST--
dom DOMNode::insertBefore() DocumentFragment child transfer (#15293)
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$a = $doc->createElement('a');
$b = $doc->createElement('b');
$root->appendChild($a);
$root->appendChild($b);
$frag = $doc->createDocumentFragment();
$frag->appendChild($doc->createElement('x'));
$frag->appendChild($doc->createElement('y'));
$root->insertBefore($frag, $b);
$names = [];
for ($i = 0; $i < $root->childNodes->length; ++$i) {
    $names[] = $root->childNodes->item($i)->nodeName;
}
echo implode(',', $names), "\n";
echo $frag->childNodes->length, "\n";
--EXPECT--
a,x,y,b
0
