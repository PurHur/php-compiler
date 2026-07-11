<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><a id="1"><b/></a></root>');
$a = $doc->documentElement->firstChild;
if (!$a instanceof DOMElement) {
    fwrite(STDERR, "fail: element not found\n");
    exit(1);
}
$b = $a->cloneNode(true);
if (!$a->isEqualNode($a)) {
    fwrite(STDERR, "fail: node should equal itself\n");
    exit(1);
}
if (!$a->isEqualNode($b)) {
    fwrite(STDERR, "fail: deep clone should be equal\n");
    exit(1);
}
$c = $doc->createElement('a');
$c->setAttribute('id', '1');
$c->appendChild($doc->createElement('b'));
if (!$a->isEqualNode($c)) {
    fwrite(STDERR, "fail: detached equal tree should match\n");
    exit(1);
}
$doc2 = new DOMDocument();
$doc2->loadXML('<root><a id="2"/></root>');
$d = $doc2->documentElement->firstChild;
if ($a->isEqualNode($d)) {
    fwrite(STDERR, "fail: different attribute should not be equal\n");
    exit(1);
}
if ($a->isSameNode($b)) {
    fwrite(STDERR, "fail: clone is not same node\n");
    exit(1);
}

echo "ok\n";
