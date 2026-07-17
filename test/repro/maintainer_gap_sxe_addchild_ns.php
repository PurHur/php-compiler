<?php

declare(strict_types=1);

$s = new SimpleXMLElement('<r/>');
$s->addChild('x', '1', 'urn:x');
echo str_replace("\n", '', $s->asXML()), "\n";
echo 'children=', count($s->children('urn:x')), "\n";
