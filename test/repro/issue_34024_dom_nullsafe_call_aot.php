<?php
// Repro #34024 — AOT inline DOM method()?->tagName must match Zend (not empty).
$d = new DOMDocument();
$d->loadXML('<r/>');
$el = $d->documentElement;
echo ($el->cloneNode(false)?->tagName ?? 'null'), PHP_EOL;
echo ($d->createElement('x')?->tagName ?? 'null'), PHP_EOL;
$x = $d->createElement('x');
echo ($el->appendChild($x)?->tagName ?? 'null'), PHP_EOL;
// Assigned-temp / non-nullsafe controls (before a second loadXML — lastCompileTimeXml is global)
$n = $el->cloneNode(false);
echo $n->tagName, PHP_EOL;
echo $el->cloneNode(false)->tagName, PHP_EOL;
$o = new DOMDocument();
$o->loadXML('<e/>');
echo ($d->importNode($o->documentElement, true)?->tagName ?? 'null'), PHP_EOL;
