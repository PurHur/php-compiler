<?php

declare(strict_types=1);

$doc = new DOMDocument();
$el = $doc->createElementNS('http://example.com', 'ex:item', 'text');
echo $el->namespaceURI, "\n";
echo $el->localName, "\n";
