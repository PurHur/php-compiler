<?php
/** Repro #20940 — Dom\Document::importLegacyNode() + living importNode class (#20940). */
$legacy = new DOMDocument();
$legacy->loadXML('<root xmlns="urn:x"><child id="c">t</child></root>');

$xml = Dom\XMLDocument::createEmpty();
echo method_exists($xml, 'importLegacyNode') ? "has_legacy\n" : "no_legacy\n";

$imported = $xml->importLegacyNode($legacy->documentElement, true);
echo 'xml_class=', get_class($imported), "\n";
echo 'xml_isa=', ($imported instanceof Dom\Element) ? "yes\n" : "no\n";
echo 'xml_tag=', $imported->tagName, "\n";
echo 'xml_ns=', $imported->namespaceURI, "\n";
echo 'xml_child=', $imported->firstElementChild?->localName, "\n";
echo 'xml_text=', $imported->textContent, "\n";

$xml->append($imported);
echo 'xml_saved=', str_contains($xml->saveXml(), '<child') ? "ok\n" : "bad\n";

$livingSrc = Dom\XMLDocument::createFromString('<a><b>x</b></a>');
$livingDst = Dom\XMLDocument::createEmpty();
$n2 = $livingDst->importNode($livingSrc->documentElement, true);
echo 'living_import=', get_class($n2), "\n";

try {
    $livingDst->importNode($legacy->documentElement, true);
    echo "living_accepts_legacy\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'Dom\\Node') ? "living_rejects_legacy\n" : ("living_err=".$e->getMessage()."\n"));
}

try {
    $xml->importLegacyNode($livingSrc->documentElement, true);
    echo "legacy_accepts_living\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'DOMNode') ? "legacy_rejects_living\n" : ("legacy_err=".$e->getMessage()."\n"));
}

$html = Dom\HTMLDocument::createEmpty();
$legacyHtml = new DOMDocument();
$p = $legacyHtml->createElement('p');
$legacyHtml->appendChild($p);
$h = $html->importLegacyNode($p, true);
echo 'html_class=', get_class($h), "\n";
