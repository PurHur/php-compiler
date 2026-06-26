<?php

declare(strict_types=1);

// Issue #11885 — LIBXML_* parse flags (ext/libxml/libxml.c).
if (!defined('LIBXML_NOENT')) {
    echo "defined_noent=0 ERR:undefined\n";
    exit(1);
}
try {
    $value = constant('LIBXML_NOENT');
} catch (\Throwable $e) {
    echo "defined_noent=1 ERR:", $e::class, "\n";
    exit(1);
}
echo 'defined_noent=1 value_noent=', $value, "\n";
$flags = [
    'LIBXML_DTDLOAD' => 4,
    'LIBXML_DTDATTR' => 8,
    'LIBXML_DTDVALID' => 16,
    'LIBXML_NOERROR' => 32,
    'LIBXML_NONET' => 2048,
];
foreach ($flags as $name => $expected) {
    if (!defined($name) || constant($name) !== $expected) {
        echo "fail:$name\n";
        exit(1);
    }
}
echo "ok\n";
