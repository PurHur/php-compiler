<?php

declare(strict_types=1);

$doc = new DOMDocument();
try {
    $doc->documentElement = $doc->createElement('root');
    fwrite(STDERR, "assigned\n");
    exit(1);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

$root = $doc->createElement('root');
$doc->appendChild($root);
if ('root' !== $doc->documentElement->nodeName) {
    fwrite(STDERR, "append path broken\n");
    exit(1);
}
echo "ok\n";
