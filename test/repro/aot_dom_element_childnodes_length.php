<?php
$src = new DOMDocument();
$e = $src->createElement('r');
$e->appendChild($src->createElement('a'));
echo 'len=', $e->childNodes->length, "\n";
