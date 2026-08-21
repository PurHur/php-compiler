<?php
$d = new DOMDocument();
$e = $d->createElement('e');
$d->appendChild($e);
$e->setAttribute('a', '1');
echo $e->getAttribute('a'), "\n";
