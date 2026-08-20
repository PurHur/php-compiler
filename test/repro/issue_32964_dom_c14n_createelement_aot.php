<?php

declare(strict_types=1);

// AOT: createElement + setAttribute + appendChild → C14N / C14NFile (#32964 / #32973)
$doc = new DOMDocument();
$el = $doc->createElement('r');
$el->setAttribute('a', '1');
$doc->appendChild($el);
$expected = '<r a="1"></r>';
$c14n = $el->C14N();
if ($expected !== $c14n) {
    fwrite(STDERR, "fail: C14N mismatch\nexpected: {$expected}\ngot: ".var_export($c14n, true)."\n");
    exit(1);
}
$tmp = tempnam(sys_get_temp_dir(), 'domc14nfile32964ce');
if (false === $tmp) {
    fwrite(STDERR, "fail: tempnam\n");
    exit(1);
}
@unlink($tmp);
$bytes = $el->C14NFile($tmp);
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
