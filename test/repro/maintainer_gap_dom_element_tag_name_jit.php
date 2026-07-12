<?php

declare(strict_types=1);

$doc = new DOMDocument();
$el = $doc->createElement('p');
echo $el->nodeName, ':', $el->tagName;
