<?php
declare(strict_types=1);

/**
 * #35272 — AOT setAttributeNS on DOMNode-typed receiver (appendChild return) must persist NS attrs.
 * NS twin of #35261.
 */
$d = new DOMDocument();
$e = $d->appendChild($d->createElement('e'));
$e->setAttributeNS('http://example.com', 'ex:a', '1');
echo 'xml=', $d->saveXML($e), "\n";
echo 'get=', var_export($e->getAttributeNS('http://example.com', 'a'), true), "\n";
echo 'has=', var_export($e->hasAttributeNS('http://example.com', 'a'), true), "\n";
$e->removeAttributeNS('http://example.com', 'a');
echo 'after_rm xml=', $d->saveXML($e), "\n";
echo 'after_rm has=', var_export($e->hasAttributeNS('http://example.com', 'a'), true), "\n";
