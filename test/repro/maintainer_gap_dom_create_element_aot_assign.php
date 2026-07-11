<?php

declare(strict_types=1);

$doc = new DOMDocument();
$el = $doc->createElement('p');
echo ($doc->createElement('x') === null) ? "inline-null\n" : "inline-obj\n";
echo ($el === null) ? "assigned-null\n" : "assigned-obj\n";
