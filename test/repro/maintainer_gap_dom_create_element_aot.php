<?php

declare(strict_types=1);

$dom = new DOMDocument();
$el = $dom->createElement('p');
echo $el->tagName, "\n";
