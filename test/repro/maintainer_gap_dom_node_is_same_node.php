<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><a id="1"/><b/></root>');
$a = $doc->getElementsByTagName('a')->item(0);
$b = $doc->getElementsByTagName('b')->item(0);

$same = $a->isSameNode($a);
if (!$same) {
    fwrite(STDERR, "fail: same node should return true\n");
    exit(1);
}

$diff = $a->isSameNode($b);
if ($diff) {
    fwrite(STDERR, "fail: different nodes should return false\n");
    exit(1);
}

$doc2 = new DOMDocument();
$doc2->loadXML('<root><a/></root>');
$a2 = $doc2->getElementsByTagName('a')->item(0);
$cross = $a->isSameNode($a2);
if ($cross) {
    fwrite(STDERR, "fail: cross-document nodes should return false\n");
    exit(1);
}

echo "ok same=1 diff=0 cross=0\n";
