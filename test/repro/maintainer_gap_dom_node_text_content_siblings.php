<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$a = $doc->getElementsByTagName('a')->item(0);
$b = $doc->getElementsByTagName('b')->item(0);
$root = $doc->documentElement;

if ($b->previousSibling !== $a) {
    fwrite(STDERR, "fail: b.previousSibling should be prior a sibling\n");
    exit(1);
}
if (null !== $a->previousSibling) {
    fwrite(STDERR, "fail: first child previousSibling should be null\n");
    exit(1);
}

$root->textContent = 'hi';
if ('hi' !== $root->textContent) {
    fwrite(STDERR, 'fail: textContent set expected hi, got '.var_export($root->textContent, true)."\n");
    exit(1);
}
if (1 !== $root->childNodes->length) {
    fwrite(STDERR, "fail: textContent set should leave one text child\n");
    exit(1);
}

echo "ok prev=a text=hi children=1\n";
