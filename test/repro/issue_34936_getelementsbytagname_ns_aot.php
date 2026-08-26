<?php
$d = new DOMDocument();
$d->loadXML('<r xmlns:x="urn:x"><x:a>hi</x:a></r>');
echo 'len='.$d->getElementsByTagName('a')->length."\n";
