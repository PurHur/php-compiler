<?php
$d = new DOMDocument();
$e = $d->createElement('x');
$d->appendChild($e);
$e->setAttributeNS('http://example.com', 'ex:aid', 'bar');
$e->setIdAttributeNS('http://example.com', 'aid', true);
$a2 = $e->getAttributeNodeNS('http://example.com', 'aid');
var_export($a2->isId());
echo "\n";
