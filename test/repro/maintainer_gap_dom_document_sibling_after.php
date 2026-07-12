<?php

declare(strict_types=1);

$doc = new DOMDocument();
$p = $doc->createElement('p');
$doc->appendChild($p);
$span = $doc->createElement('span');
$p->after($span);
echo $doc->saveXML();
