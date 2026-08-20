<?php

declare(strict_types=1);

// AOT: documentElement->C14NFile() after loadXML (#32964)
$doc = new DOMDocument();
$doc->loadXML('<r a="1"/>');
$expected = '<r a="1"></r>';
$tmp = tempnam(sys_get_temp_dir(), 'domc14nfile32964');
if (false === $tmp) {
    fwrite(STDERR, "fail: tempnam\n");
    exit(1);
}
@unlink($tmp);
$bytes = $doc->documentElement->C14NFile($tmp);
if (!is_int($bytes) || $bytes !== strlen($expected)) {
    fwrite(STDERR, 'fail: C14NFile byte count expected '.strlen($expected).', got '.(is_scalar($bytes) ? var_export($bytes, true) : gettype($bytes))."\n");
    @unlink($tmp);
    exit(1);
}
$body = file_get_contents($tmp);
@unlink($tmp);
if ($expected !== $body) {
    fwrite(STDERR, "fail: C14NFile body mismatch\nexpected: {$expected}\ngot: ".var_export($body, true)."\n");
    exit(1);
}

echo "ok bytes={$bytes}\n";
