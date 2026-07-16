<?php
/**
 * Repro #19708 — SimpleXMLElement::addAttribute() with namespace but no prefix
 * must warn and leave the element unchanged (php-src ext/simplexml/sxe.c).
 */
$s = new SimpleXMLElement('<r/>');
$s->addAttribute('x', '1', 'urn:n');
echo trim($s->asXML()), "\n";

$t = new SimpleXMLElement('<r/>');
$t->addAttribute('n:x', '1', 'urn:n');
echo trim($t->asXML()), "\n";
echo (string) $t['n:x'], "\n";
