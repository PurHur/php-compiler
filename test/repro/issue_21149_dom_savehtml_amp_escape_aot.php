<?php

declare(strict_types=1);

/**
 * Issue #21149 — AOT: saveHTML text escaping via full-document dump
 * (node-arg saveHTML AOT lowering is document-only ABI; #18268).
 */
$doc = new DOMDocument();
$doc->loadHTML(
    '<html><body><p>Hi &amp; bye</p></body></html>',
    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
);
echo trim($doc->saveHTML());
