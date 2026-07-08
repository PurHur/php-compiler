<?php

declare(strict_types=1);

// Compile-only (#17391): DOMDocument::createElement JIT bridge links; execute tier blocked on helper TU.
$dom = new DOMDocument();
$el = $dom->createElement('p');
echo $el->tagName, "\n";
