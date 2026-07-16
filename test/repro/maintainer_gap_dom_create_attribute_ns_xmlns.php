<?php

declare(strict_types=1);

/**
 * #19458 — createAttributeNS + setAttributeNodeNS must declare xmlns:prefix.
 */

$d = new DOMDocument();
$d->loadXML('<r/>');
$a = $d->createAttributeNS('urn:x', 'p:id');
$a->value = '1';
$d->documentElement->setAttributeNodeNS($a);
$xml = $d->saveXML($d->documentElement);
$lookup = $d->documentElement->lookupNamespaceURI('p') ?? 'NULL';

if (trim($xml) !== '<r xmlns:p="urn:x" p:id="1"/>') {
    fwrite(STDERR, "fail: saveXML got {$xml}");
    exit(1);
}
if ('urn:x' !== $lookup) {
    fwrite(STDERR, "fail: lookupNamespaceURI(p) got {$lookup}\n");
    exit(1);
}

echo "ok\n";
