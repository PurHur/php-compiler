<?php
declare(strict_types=1);
$doc1 = new DOMDocument();
$doc1->loadXML('<x>text</x>');
$doc2 = new DOMDocument();
$imported = $doc2->importNode($doc1->documentElement, true);
echo $imported->nodeName, ':', $imported->textContent, "\n";
$doc2->appendChild($imported);
$xml = $doc2->saveXML();
if (!str_contains($xml, 'text')) {
    fwrite(STDERR, "fail: appendChild after importNode lost text: {$xml}\n");
    exit(1);
}
echo "ok\n";
