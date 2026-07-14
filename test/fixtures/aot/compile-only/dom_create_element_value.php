<?php

declare(strict_types=1);

$doc = new DOMDocument();
$node = $doc->createElement('p', 'hello');
echo $node->textContent, "\n";
echo $doc->saveXML($node), "\n";
