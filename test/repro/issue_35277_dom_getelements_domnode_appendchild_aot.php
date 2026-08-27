<?php
declare(strict_types=1);

/**
 * #35277 — AOT getElementsByTagName(NS) / removeAttributeNode on DOMNode-typed
 * receiver (appendChild return) must match Zend (no SIGSEGV / silent no-op).
 * Peer of #35261 / #35272 attribute proxies.
 */
$d = new DOMDocument();
$e = $d->appendChild($d->createElement('e'));
$e->appendChild($d->createElement('c'));
$e->appendChild($d->createElementNS('http://example.com', 'ex:n'));
echo 'tag=', $e->getElementsByTagName('c')->length, "\n";
echo 'ns=', $e->getElementsByTagNameNS('http://example.com', 'n')->length, "\n";
$e->setAttribute('a', '1');
$e->removeAttributeNode($e->getAttributeNode('a'));
echo 'has=', var_export($e->hasAttribute('a'), true), "\n";
echo 'xml=', $d->saveXML($e), "\n";
