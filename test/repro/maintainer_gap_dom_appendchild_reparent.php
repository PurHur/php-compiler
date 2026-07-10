<?php
declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$root = $doc->documentElement;
$a = $root->firstChild;
$root->appendChild($a);
echo 'count=', $root->childNodes->length, "\n";
echo 'first=', $root->firstChild->nodeName, "\n";

$doc2 = new DOMDocument();
$root2 = $doc2->createElement('root');
$doc2->appendChild($root2);
$node = $doc2->createElement('x');
$root2->appendChild($node);
$frag = $doc2->createDocumentFragment();
$frag->appendChild($node);
echo 'hasChild=', var_export($root2->hasChildNodes(), true), "\n";
echo 'fragLen=', $frag->childNodes->length, "\n";

if (2 !== $root->childNodes->length) {
    fwrite(STDERR, "fail: reparent child count\n");
    exit(1);
}
if ('b' !== $root->firstChild->nodeName) {
    fwrite(STDERR, "fail: reparent first child\n");
    exit(1);
}
if ($root2->hasChildNodes()) {
    fwrite(STDERR, "fail: source parent still has children after fragment move\n");
    exit(1);
}
if (1 !== $frag->childNodes->length) {
    fwrite(STDERR, "fail: fragment child count\n");
    exit(1);
}

echo "ok\n";
