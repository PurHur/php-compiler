--TEST--
DOMDocument::importNode() after createComment/CDATA/PI/DocumentFragment (#35871, ext/dom/document.c)
--FILE--
<?php
$src = new DOMDocument();
$c = $src->createComment('hi');
$dst = new DOMDocument();
$n = $dst->importNode($c, true);
echo $n->nodeName, '|', $n->nodeType, '|', $n->nodeValue, "\n";
$dst->appendChild($dst->createElement('r'))->appendChild($n);
echo trim($dst->saveXML($dst->documentElement)), "\n";

$src2 = new DOMDocument();
$cd = $src2->createCDATASection('x');
$pi = $src2->createProcessingInstruction('pi', 'data');
$dst2 = new DOMDocument();
$n1 = $dst2->importNode($cd, true);
$n2 = $dst2->importNode($pi, true);
echo $n1->nodeName, '|', $n1->nodeType, '|', $n1->nodeValue, "\n";
echo $n2->nodeName, '|', $n2->nodeType, '|', $n2->nodeValue, "\n";

$src3 = new DOMDocument();
$f = $src3->createDocumentFragment();
$f->appendChild($src3->createElement('a'));
$f->appendChild($src3->createTextNode('t'));
$dst3 = new DOMDocument();
$nf = $dst3->importNode($f, true);
echo $nf->nodeName, '|', $nf->nodeType, '|', $nf->childNodes->length, '|', trim($dst3->saveXML($nf)), "\n";
?>
--EXPECT--
#comment|8|hi
<r><!--hi--></r>
#cdata-section|4|x
pi|7|data
#document-fragment|11|2|<a/>t
