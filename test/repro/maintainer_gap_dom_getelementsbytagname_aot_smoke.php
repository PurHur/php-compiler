<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><a/></root>');
$list = $doc->getElementsByTagName('a');
echo 'count=', $list->length, "\n";
