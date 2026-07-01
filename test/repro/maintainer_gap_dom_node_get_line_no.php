<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><item/></root>');
$el = $doc->documentElement->firstChild;
$line = $el->getLineNo();
if (!\is_int($line) || $line < 1) {
    fwrite(STDERR, "fail: getLineNo expected positive int, got {$line}\n");
    exit(1);
}

echo "ok line={$line}\n";
