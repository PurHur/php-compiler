<?php

declare(strict_types=1);

$doc = new DOMDocument();
$node = $doc->createElement('p', 'hello');
if ('hello' !== $node->textContent) {
    fwrite(STDERR, 'fail: textContent='.var_export($node->textContent, true)."\n");
    exit(1);
}
$xml = $doc->saveXML($node);
if ('<p>hello</p>' !== $xml) {
    fwrite(STDERR, "fail: saveXML={$xml}\n");
    exit(1);
}

$empty = $doc->createElement('span');
if ('' !== $empty->textContent) {
    fwrite(STDERR, 'fail: empty value textContent='.var_export($empty->textContent, true)."\n");
    exit(1);
}

echo "createElement value ok\n";
