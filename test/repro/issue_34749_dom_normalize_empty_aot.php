<?php
$d = new DOMDocument();
$e = $d->appendChild($d->createElement('r'));
$e->normalize();
echo "ok\n";
