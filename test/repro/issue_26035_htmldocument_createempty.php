<?php
// #26035 — Dom\HTMLDocument::createEmpty() starts empty (php-src ext/dom/html_document.c).
// Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_26035_htmldocument_createempty.php

$dom = Dom\HTMLDocument::createEmpty();
echo 'before=', $dom->documentElement ? $dom->documentElement->nodeName : 'NULL', "\n";
$dom->appendChild($dom->createElement('template'));
echo 'after=', $dom->documentElement ? $dom->documentElement->nodeName : 'NULL', "\n";
echo 'childElementCount=', $dom->childElementCount, "\n";
$xml = str_replace("\n", ' ', trim($dom->saveXml()));
echo 'saveXml_template_only=', (str_contains($xml, '<template') && !str_contains($xml, '<html')) ? 'yes' : 'no', "\n";
echo 'saveXml=', $xml, "\n";

$impl = Dom\HTMLDocument::createEmpty()->implementation->createHTMLDocument('T');
echo 'impl_root=', $impl->documentElement ? $impl->documentElement->nodeName : 'NULL', "\n";
echo 'impl_title=', $impl->title, "\n";
echo 'impl_body=', $impl->body !== null ? 'set' : 'NULL', "\n";
