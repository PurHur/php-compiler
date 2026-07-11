<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><a/></root>');
$root = $doc->documentElement;

if (1 !== $root->nodeType) {
    fwrite(STDERR, "fail: nodeType expected 1 got {$root->nodeType}\n");
    exit(1);
}
if ($root->ownerDocument !== $doc) {
    fwrite(STDERR, "fail: ownerDocument should be owning document\n");
    exit(1);
}
if ('' !== $root->nodeValue) {
    fwrite(STDERR, 'fail: nodeValue read expected empty string, got '.var_export($root->nodeValue, true)."\n");
    exit(1);
}

$root->nodeValue = 'hello';
if ('hello' !== $root->nodeValue) {
    fwrite(STDERR, 'fail: nodeValue after set expected hello, got '.var_export($root->nodeValue, true)."\n");
    exit(1);
}
if (1 !== $root->childNodes->length) {
    fwrite(STDERR, "fail: nodeValue set should leave one text child, got {$root->childNodes->length}\n");
    exit(1);
}

$leaf = $doc->createElement('leaf');
if ($leaf->ownerDocument !== $doc) {
    fwrite(STDERR, "fail: createElement leaf should belong to document\n");
    exit(1);
}

echo "ok nodeType=1 owner=doc nodeValue=hello children=1\n";
