<?php

declare(strict_types=1);

function dom_compact_xml(string $xml): string
{
    return (string) preg_replace('/\s+/', '', $xml);
}

$doc = new DOMDocument();
$p = $doc->createElement('p');
$doc->appendChild($p);

$frag = $doc->createDocumentFragment();
$frag->appendChild($doc->createElement('a'));
$p->after($frag);
$xml = dom_compact_xml($doc->saveXML());
if ('<?xmlversion="1.0"?><p/><a/>' !== $xml) {
    fwrite(STDERR, "fail: fragment after expected compact <p/><a/> got {$xml}\n");
    exit(1);
}

echo "document-parent fragment after\n";
