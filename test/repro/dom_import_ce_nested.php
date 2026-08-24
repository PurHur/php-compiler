<?php
/**
 * #34302 — nested appendChild(createElement) then importNode must AOT-compile.
 * Split createElement is the control (already green on master).
 */
$src = new DOMDocument();
$src->loadXML('<r><a>t</a></r>');

$dst = new DOMDocument('1.0');
$dst->appendChild($dst->createElement('r'));
$n = $dst->importNode($src->documentElement, true);
echo $n->nodeName, "\n";

$dst2 = new DOMDocument('1.0');
$el = $dst2->createElement('r');
$dst2->appendChild($el);
$n2 = $dst2->importNode($src->documentElement, true);
echo $n2->nodeName, "\n";
