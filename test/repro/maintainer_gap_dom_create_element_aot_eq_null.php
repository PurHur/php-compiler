<?php

declare(strict_types=1);

$doc = new DOMDocument();
$el = $doc->createElement('p');
echo ($el === null) ? "null\n" : "obj\n";
