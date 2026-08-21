--TEST--
AOT: insertBefore(DocumentFragment) before middle child — saveXML order (#33327)
--FILE--
<?php
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $r->appendChild($d->createElement('a'));
$b = $r->appendChild($d->createElement('b'));
$f = $d->createDocumentFragment();
$f->appendChild($d->createElement('x'));
$f->appendChild($d->createElement('y'));
$r->insertBefore($f, $b);
echo 'len=', $r->childNodes->length, "\n";
for ($i = 0; $i < $r->childNodes->length; $i++) {
    $n = $r->childNodes->item($i);
    echo 'i', $i, '=', $n ? $n->nodeName : 'null', "\n";
}
echo 'xml=', $d->saveXML($r), "\n";
--EXPECT--
len=4
i0=a
i1=x
i2=y
i3=b
xml=<r><a/><x/><y/><b/></r>
