<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$list = $doc->getElementsByTagName('a');
echo 'before=', $list->length, "\n";
$doc->documentElement->appendChild($doc->createElement('a'));
echo 'after=', $list->length, "\n";
