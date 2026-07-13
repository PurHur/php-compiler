<?php

declare(strict_types=1);

$doc = new DOMDocument();
if (null !== $doc->xmlEncoding) {
    fwrite(STDERR, "fail: fresh xmlEncoding should be null\n");
    exit(1);
}

$doc->loadXML('<?xml version="1.0" encoding="UTF-8"?><root/>');
if ('UTF-8' !== $doc->xmlEncoding) {
    fwrite(STDERR, 'fail: loaded xmlEncoding expected UTF-8, got '.var_export($doc->xmlEncoding, true)."\n");
    exit(1);
}
if ($doc->encoding !== $doc->xmlEncoding) {
    fwrite(STDERR, "fail: encoding and xmlEncoding diverged\n");
    exit(1);
}

$doc->encoding = 'ISO-8859-1';
if ('ISO-8859-1' !== $doc->xmlEncoding) {
    fwrite(STDERR, "fail: xmlEncoding should mirror encoding assign\n");
    exit(1);
}

try {
    $doc->xmlEncoding = 'X';
    fwrite(STDERR, "fail: xmlEncoding write should throw\n");
    exit(1);
} catch (Error $e) {
    if ('Cannot write read-only property DOMDocument::$xmlEncoding' !== $e->getMessage()) {
        fwrite(STDERR, 'fail: wrong message: '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
