<?php

declare(strict_types=1);

$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->append($root);

if ('root' !== $doc->documentElement->nodeName) {
    echo 'fail: documentElement=', $doc->documentElement->nodeName ?? 'null', "\n";
    exit(1);
}

$doc2 = new DOMDocument();
$first = $doc2->createElement('a');
$second = $doc2->createElement('b');
$doc2->prepend($first, $second);

if ('a' !== $doc2->documentElement->nodeName) {
    echo 'fail: prepend documentElement=', $doc2->documentElement->nodeName ?? 'null', "\n";
    exit(1);
}

echo "root\n";
