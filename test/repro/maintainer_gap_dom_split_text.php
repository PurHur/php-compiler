<?php

declare(strict_types=1);

$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$text = $doc->createTextNode('hello');
$root->appendChild($text);

$tail = $text->splitText(2);
if ('he' !== $text->data) {
    fwrite(STDERR, "fail: parent data expected 'he', got ".var_export($text->data, true)."\n");
    exit(1);
}
if ('llo' !== $tail->data) {
    fwrite(STDERR, "fail: tail data expected 'llo', got ".var_export($tail->data, true)."\n");
    exit(1);
}
if ($tail->previousSibling !== $text) {
    fwrite(STDERR, "fail: tail previousSibling should be original text node\n");
    exit(1);
}
if ($text->nextSibling !== $tail) {
    fwrite(STDERR, "fail: parent nextSibling should be tail text node\n");
    exit(1);
}
if ($doc->saveXML($root) !== '<root>hello</root>') {
    fwrite(STDERR, "fail: serialized tree expected <root>hello</root>, got ".$doc->saveXML($root)."\n");
    exit(1);
}

$detached = $doc->createTextNode('world');
$detachedTail = $detached->splitText(2);
if ('wo' !== $detached->data || 'rld' !== $detachedTail->data) {
    fwrite(STDERR, "fail: detached split mismatch\n");
    exit(1);
}
if (null !== $detachedTail->parentNode) {
    fwrite(STDERR, "fail: detached tail should have no parent\n");
    exit(1);
}

try {
    $text->splitText(-1);
    echo "noexception\n";
    exit(1);
} catch (DOMException $e) {
    if (1 !== $e->getCode()) {
        echo "badcode\n";
        exit(1);
    }
}

echo "ok\n";
