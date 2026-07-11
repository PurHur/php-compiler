<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><a/></root>');
$root = $doc->documentElement;
$leaf = $doc->createElement('leaf');

$populated = $root->hasChildNodes();
$empty = $leaf->hasChildNodes();
if (!$populated) {
    fwrite(STDERR, "fail: root with child should haveChildNodes true\n");
    exit(1);
}
if ($empty) {
    fwrite(STDERR, "fail: detached empty leaf should haveChildNodes false\n");
    exit(1);
}

echo "ok populated=1 empty=0\n";
