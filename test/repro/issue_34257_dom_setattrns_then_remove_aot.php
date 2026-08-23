<?php
declare(strict_types=1);

/**
 * AOT: setAttributeNS then setAttribute then removeAttributeNS — saveXML (#34257 peer).
 * Must keep xmlns:prefix and non-NS attrs; drop only the removed NS attr.
 */
$d = new DOMDocument();
$d->loadXML('<r/>');
$e = $d->documentElement;
$e->setAttributeNS('urn:x', 'p:a', '1');
$e->setAttribute('b', '2');
$e->removeAttributeNS('urn:x', 'a');
echo $d->saveXML($e), "\n";
