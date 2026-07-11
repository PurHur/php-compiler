<?php

declare(strict_types=1);

libxml_use_internal_errors(true);
libxml_clear_errors();

$doc = new DOMDocument();
$doc->validateOnParse = true;
$xml = '<?xml version="1.0"?><!DOCTYPE root [<!ELEMENT root (#PCDATA)>]><undeclared/>';
@$doc->loadXML($xml);

$hasUndeclared = false;
foreach (libxml_get_errors() as $error) {
    if (str_contains($error->message, 'No declaration for element')) {
        $hasUndeclared = true;
        break;
    }
}

if (!$hasUndeclared) {
    fwrite(STDERR, "fail: expected libxml warning for undeclared root element\n");
    exit(1);
}

echo "ok\n";
