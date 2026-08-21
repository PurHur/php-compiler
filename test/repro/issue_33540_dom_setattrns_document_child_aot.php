<?php
declare(strict_types=1);

// Sibling probe — setAttributeNS after appendChild(document).
$d = new DOMDocument();
$e = $d->createElement('x');
$d->appendChild($e);
$e->setAttributeNS(null, 'k', 'v');
echo $e->getAttribute('k');
echo "\n";
echo trim($d->saveXML($e));
echo "\n";
