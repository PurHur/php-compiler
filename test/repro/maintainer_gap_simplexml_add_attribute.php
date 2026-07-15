<?php
// Repro #19307 — SimpleXMLElement::addAttribute must mutate element attrs (ext/simplexml/sxe.c).
$x = new SimpleXMLElement('<r/>');
$x->addAttribute('k', 'v');
echo trim($x->asXML()), PHP_EOL;
