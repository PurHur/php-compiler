<?php

declare(strict_types=1);

$doc = new DOMDocument();
$p = $doc->createElement('p');
$doc->appendChild($p);
$frag = $doc->createDocumentFragment();
$span = $doc->createElement('span');
$frag->appendChild($span);
$p->after($frag);
echo $doc->saveXML();
