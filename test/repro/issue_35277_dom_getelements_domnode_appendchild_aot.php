<?php
declare(strict_types=1);

/**
 * #35277 — AOT getElementsByTagName* / removeAttributeNode on DOMNode-typed appendChild return.
 * Peer of #35261 / #35272 (Element methods missing from domnode:: allowlist).
 */
$d = new DOMDocument();
$e = $d->appendChild($d->createElement('e'));
$e->appendChild($d->createElement('c'));
$e->appendChild($d->createElementNS('urn:x', 'x:n'));
echo 'by_tag=', $e->getElementsByTagName('c')->length, "\n";
echo 'by_ns=', $e->getElementsByTagNameNS('urn:x', 'n')->length, "\n";

$e->setAttribute('a', '1');
$attr = $e->getAttributeNode('a');
$e->removeAttributeNode($attr);
echo 'xml=', $d->saveXML($e), "\n";
echo 'has=', var_export($e->hasAttribute('a'), true), "\n";
