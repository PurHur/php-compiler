<?php
/** AOT: cloneNode(true) after createElement+appendChild (no loadXML). */
$doc = new DOMDocument();
$el = $doc->createElement('a');
$el->appendChild($doc->createElement('b'));
$c = $el->cloneNode(true);
echo $c->nodeName, '|', $c->firstChild?->nodeName ?? 'null';
echo '|', $c->isSameNode($el) ? 'same' : 'diff';
echo "\n";
$shallow = $el->cloneNode(false);
echo $shallow->nodeName, '|', $shallow->hasChildNodes() ? 'kids' : 'empty', "\n";
