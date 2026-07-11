<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root xmlns="http://example.com"><child>text</child></root>');
$expected = '<root xmlns="http://example.com"><child>text</child></root>';
$c14n = $doc->documentElement->C14N();
if (!is_string($c14n)) {
    fwrite(STDERR, "fail: C14N() did not return string\n");
    exit(1);
}
if ($expected !== $c14n) {
    fwrite(STDERR, "fail: C14N mismatch\nexpected: {$expected}\ngot: {$c14n}\n");
    exit(1);
}

$tmp = tempnam(sys_get_temp_dir(), 'domc14n');
if (false === $tmp) {
    fwrite(STDERR, "fail: tempnam\n");
    exit(1);
}
$bytes = $doc->documentElement->C14NFile($tmp);
if (!is_int($bytes) || $bytes !== strlen($expected)) {
    fwrite(STDERR, "fail: C14NFile byte count expected ".strlen($expected).', got '.var_export($bytes, true)."\n");
    @unlink($tmp);
    exit(1);
}
$fileBody = file_get_contents($tmp);
@unlink($tmp);
if ($expected !== $fileBody) {
    fwrite(STDERR, "fail: C14NFile body mismatch\n");
    exit(1);
}

echo 'ok bytes='.strlen($c14n)."\n";
