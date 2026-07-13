<?php

declare(strict_types=1);

$doc = new DOMDocument();
$methods = [
    'normalizeDocument',
    'xinclude',
    'registerNodeClass',
    'schemaValidate',
    'relaxNGValidate',
    'schemaValidateSource',
    'relaxNGValidateSource',
];
foreach ($methods as $method) {
    if (!method_exists($doc, $method)) {
        fwrite(STDERR, "fail: missing method {$method}\n");
        exit(1);
    }
}

$doc->loadXML('<root/>');
$doc->normalizeDocument();

echo "ok\n";
