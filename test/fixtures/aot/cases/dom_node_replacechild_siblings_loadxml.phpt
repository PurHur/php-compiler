--TEST--
AOT: DOMNode::replaceChild keeps remaining siblings in saveXML (#28671)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/></r>');
$r = $doc->documentElement;
$a = $r->firstChild;
$n = $doc->createElement('c');
$r->replaceChild($n, $a);
echo $doc->saveXML($r), "\n";
echo 'len=', $r->childNodes->length, "\n";
echo 'first=', $r->firstChild->nodeName, ' last=', $r->lastChild->nodeName, "\n";
--EXPECT--
<r><c/><b/></r>
len=2
first=c last=b
