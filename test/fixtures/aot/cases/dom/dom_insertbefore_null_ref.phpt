--TEST--
AOT: DOMNode::insertBefore($node, null) / omitted / variable null ≡ append (#33031 / re-#26458)
--FILE--
<?php
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('root'));
$a = $d->createElement('a');
$b = $d->createElement('b');
$r->appendChild($a);
$ret = $r->insertBefore($b, null);
echo $r->childNodes->length, ' ', $d->saveXML($r), ' ', $ret->nodeName, "\n";

$c = $d->createElement('c');
$r->insertBefore($c);
echo $r->childNodes->length, ' ', $d->saveXML($r), "\n";

$doc = new DOMDocument();
$doc->loadXML('<r><a/></r>');
$root = $doc->documentElement;
$x = $doc->createElement('x');
$ref = null;
$root->insertBefore($x, $ref);
echo $doc->saveXML($root), "\n";
?>
--EXPECT--
2 <root><a/><b/></root> b
3 <root><a/><b/><c/></root>
<r><a/><x/></r>
