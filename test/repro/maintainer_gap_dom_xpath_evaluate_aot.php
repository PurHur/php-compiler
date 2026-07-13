<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><child id="1">a</child></root>');
$xpath = new DOMXPath($doc);
$bool = $xpath->evaluate('boolean(//child)');
echo (int) $bool, "\n";
$count = $xpath->evaluate('count(//child)');
echo (int) $count, "\n";
