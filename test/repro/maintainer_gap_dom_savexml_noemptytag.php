<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><a/></root>');

$full = $doc->saveXML(null, LIBXML_NOEMPTYTAG);
$node = $doc->documentElement;
$nodeXml = $doc->saveXML($node, LIBXML_NOEMPTYTAG);
$html = $doc->saveHTML();

$fullOk = str_contains($full, '<a></a>') && !str_contains($full, '<a/>');
$nodeOk = str_contains($nodeXml, '<a></a>') && !str_contains($nodeXml, '<a/>');
$htmlOk = str_contains($html, '<p></p>') || str_contains($html, '<a></a>');

echo 'full=', $fullOk ? 'expanded' : 'self_closing', "\n";
echo 'node=', $nodeOk ? 'expanded' : (str_contains($nodeXml, 'TypeError') ? 'TypeError' : 'bad'), "\n";
echo 'html=', $htmlOk ? 'expanded' : 'self_closing', "\n";

if (!$fullOk || !$nodeOk) {
    exit(1);
}
