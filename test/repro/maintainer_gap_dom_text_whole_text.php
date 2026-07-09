<?php

declare(strict_types=1);

$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$text = $doc->createTextNode('hello');
$root->appendChild($text);
$tail = $text->splitText(2);
if ('hello' !== $text->wholeText) {
    fwrite(STDERR, "fail: head wholeText expected hello, got {$text->wholeText}\n");
    exit(1);
}
if ('hello' !== $tail->wholeText) {
    fwrite(STDERR, "fail: tail wholeText expected hello, got {$tail->wholeText}\n");
    exit(1);
}
$root->appendChild($doc->createTextNode('world'));
$mid = $root->firstChild;
if ('helloworld' !== $mid->wholeText) {
    fwrite(STDERR, "fail: merged wholeText expected helloworld, got {$mid->wholeText}\n");
    exit(1);
}
$detached = $doc->createTextNode('solo');
if ('solo' !== $detached->wholeText) {
    fwrite(STDERR, "fail: detached wholeText expected solo, got {$detached->wholeText}\n");
    exit(1);
}
echo "ok\n";
