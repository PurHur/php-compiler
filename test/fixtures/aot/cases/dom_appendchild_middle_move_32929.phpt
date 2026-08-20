--TEST--
AOT: DOMNode::appendChild same-parent move middle/noop/first (#32929)
--FILE--
<?php
function dump(DOMElement $r): void
{
    $parts = [];
    $len = $r->childNodes->length;
    for ($i = 0; $i < $len; $i++) {
        $parts[] = $r->childNodes->item($i)->nodeName;
    }
    echo implode(',', $parts), "\n";
    echo $len, "\n";
}
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$r = $d->documentElement;
$r->appendChild($r->childNodes->item(1));
dump($r);
$r->appendChild($r->lastChild);
dump($r);
$r->appendChild($r->firstChild);
dump($r);
--EXPECT--
a,c,b
3
a,c,b
3
c,b,a
3
