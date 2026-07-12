<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><a/></root>');
$node = $doc->documentElement->firstChild;

if (!method_exists($doc, 'adoptNode')) {
    echo "fail: adoptNode not registered\n";
    exit(1);
}

try {
    $doc->adoptNode($node);
    echo "fail: adoptNode should throw\n";
    exit(1);
} catch (Error $e) {
    if ('Not yet implemented' !== $e->getMessage()) {
        echo 'fail: wrong message: ', $e->getMessage(), "\n";
        exit(1);
    }
    echo "ok\n";
}
