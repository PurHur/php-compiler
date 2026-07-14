<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root/>');
$doc->documentElement->appendChild($doc->createElement('a'));
echo "ok\n";
