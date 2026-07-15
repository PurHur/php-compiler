<?php

declare(strict_types=1);

$doc = new DOMDocument();
$p = $doc->createElement('p');
$doc->appendChild($p);
$p->append('hello', $doc->createElement('b'), ' world');
echo "ok\n";
