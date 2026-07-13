<?php

declare(strict_types=1);

$xml = '<root><item id="1">a</item><item id="2">b</item></root>';
$doc = new DOMDocument();
$doc->loadXML($xml);
$xpath = new DOMXPath($doc);
$nodes = $xpath->query('//item[@id="2"]');
echo $nodes->length, "\n";
echo $nodes->item(0)->textContent, "\n";
