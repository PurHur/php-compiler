--TEST--
DOMDocument::importNode() Comment / PI / EntityReference + deep trees (#20157, ext/dom/document.c)
--FILE--
<?php
$src = new DOMDocument();
$src->loadXML('<r/>');
$dst = new DOMDocument();

$srcComment = $src->createComment('hello');
$c = $dst->importNode($srcComment, true);
echo $c instanceof DOMComment ? 'comment-ok' : 'comment-bad', "\n";
echo $c->nodeValue, "\n";
echo ($c->ownerDocument === $dst) ? 'comment-owner' : 'comment-owner-bad', "\n";

$srcPi = $src->createProcessingInstruction('xml-stylesheet', 'href="a"');
$pi = $dst->importNode($srcPi, true);
echo $pi instanceof DOMProcessingInstruction ? 'pi-ok' : 'pi-bad', "\n";
echo $pi->nodeName, ':', $pi->nodeValue, "\n";

$srcEref = $src->createEntityReference('amp');
$eref = $dst->importNode($srcEref, true);
echo $eref instanceof DOMEntityReference ? 'eref-ok' : 'eref-bad', "\n";
echo $eref->nodeName, "\n";

$src2 = new DOMDocument();
$src2->loadXML('<r><a><!--c--><?pi d?><b/></a></r>');
$dst2 = new DOMDocument();
$child = $src2->documentElement->firstChild;
$n = $dst2->importNode($child, true);
echo $dst2->saveXML($n), "\n";
echo $n->childNodes->length, "\n";
?>
--EXPECT--
comment-ok
hello
comment-owner
pi-ok
xml-stylesheet:href="a"
eref-ok
amp
<a><!--c--><?pi d?><b/></a>
3
