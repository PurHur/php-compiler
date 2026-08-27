<?php
declare(strict_types=1);

/**
 * #35303 — AOT setIdAttributeNS after createElement+setAttributeNS must register getElementById.
 * Peer of #29284 (loadXML path) / #33957 (non-NS setIdAttribute).
 */
$d = new DOMDocument();
$e = $d->createElement('e');
$d->appendChild($e);
$e->setAttributeNS('urn:x', 'x:id', 'y');
$e->setIdAttributeNS('urn:x', 'id', true);
$f = $d->getElementById('y');
echo 'id=', ($f ? $f->nodeName : 'null'), "\n";
echo 'xml=', $d->saveXML($e), "\n";

// DOMNode-typed appendChild return (same Element methods).
$d2 = new DOMDocument();
$e2 = $d2->appendChild($d2->createElement('e'));
$e2->setAttributeNS('urn:x', 'x:id', 'z');
$e2->setIdAttributeNS('urn:x', 'id', true);
$f2 = $d2->getElementById('z');
echo 'node_id=', ($f2 ? $f2->nodeName : 'null'), "\n";
