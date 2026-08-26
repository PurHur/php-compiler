<?php
/** #35180 — createAttributeNS with root still returns DOMAttr. */
$d = new DOMDocument();
$d->appendChild($d->createElement('r'));
$a = $d->createAttributeNS('urn:x', 'x:id');
echo ($a instanceof DOMAttr) ? 'DOMAttr' : get_debug_type($a);
echo ' type=', (string) $a->nodeType;
echo ' name=', $a->nodeName;
echo "\n";
