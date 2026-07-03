<?php
$doc = new DOMDocument();
$doc->loadXML('<?xml version="1.0"?><root xmlns:ex="http://example.com"><ex:child/><ex:child/></root>');
$list = $doc->getElementsByTagNameNS('http://example.com', 'child');
$ok = 2 === $list->length;
echo $ok ? "OK count={$list->length}\n" : "FAIL count={$list->length}\n";
exit($ok ? 0 : 1);
