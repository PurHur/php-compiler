<?php

declare(strict_types=1);

$doc = new DOMDocument();

try {
    $doc->loadHTML('');
    fwrite(STDERR, "fail: loadHTML should throw ValueError for empty source\n");
    exit(1);
} catch (ValueError $e) {
    if ($e->getMessage() !== 'DOMDocument::loadHTML(): Argument #1 ($source) must not be empty') {
        fwrite(STDERR, 'fail: loadHTML message: '.$e->getMessage()."\n");
        exit(1);
    }
}

try {
    $doc->loadXML('');
    fwrite(STDERR, "fail: loadXML should throw ValueError for empty source\n");
    exit(1);
} catch (ValueError $e) {
    if ($e->getMessage() !== 'DOMDocument::loadXML(): Argument #1 ($source) must not be empty') {
        fwrite(STDERR, 'fail: loadXML message: '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
