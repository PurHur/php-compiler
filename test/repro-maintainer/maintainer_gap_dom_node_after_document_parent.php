<?php

declare(strict_types=1);

function dom_compact_xml(string $xml): string
{
    return (string) preg_replace('/\s+/', '', $xml);
}

$doc = new DOMDocument();
$p = $doc->createElement('p');
$doc->appendChild($p);

$span = $doc->createElement('span');
$p->after($span);
$afterXml = dom_compact_xml($doc->saveXML());
if ('<?xmlversion="1.0"?><p/><span/>' !== $afterXml) {
    fwrite(STDERR, "fail: after expected compact <p/><span/> got {$afterXml}\n");
    exit(1);
}

$span2 = $doc->createElement('span2');
$p->before($span2);
$beforeXml = dom_compact_xml($doc->saveXML());
if ('<?xmlversion="1.0"?><span2/><p/><span/>' !== $beforeXml) {
    fwrite(STDERR, "fail: before expected compact <span2/><p/><span/> got {$beforeXml}\n");
    exit(1);
}

$types = [];
foreach ($doc->childNodes as $n) {
    $types[] = \is_object($n) ? $n::class : \gettype($n);
}
if (['DOMElement', 'DOMElement', 'DOMElement'] !== $types) {
    fwrite(STDERR, 'fail: childNodes types ' . \json_encode($types) . "\n");
    exit(1);
}

echo "document-parent after/before + childNodes\n";
