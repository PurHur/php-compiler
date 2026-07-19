--TEST--
Dom\Document::importLegacyNode() — legacy DOM* → Dom\Element / HTMLElement (#20940)
--SKIPIF--
<?php
if (!class_exists('Dom\\XMLDocument') || !method_exists('Dom\\XMLDocument', 'importLegacyNode')) {
    die('skip Dom\\Document::importLegacyNode requires PHP_COMPILER_PROFILE=8.4 (#20940)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$legacy = new DOMDocument();
$legacy->loadXML('<root xmlns="urn:x"><child>t</child></root>');
$xml = Dom\XMLDocument::createEmpty();
$n = $xml->importLegacyNode($legacy->documentElement, true);
echo get_class($n), "\n";
echo ($n instanceof Dom\Element) ? "isa\n" : "not\n";
echo $n->tagName, ':', $n->namespaceURI, ':', $n->textContent, "\n";

$src = Dom\XMLDocument::createFromString('<a/>');
$dst = Dom\XMLDocument::createEmpty();
$m = $dst->importNode($src->documentElement, false);
echo get_class($m), "\n";

try {
    $dst->importNode($legacy->documentElement, false);
    echo "bad-accept\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'Dom\\Node') ? "reject-legacy\n" : "other\n";
}

try {
    $xml->importLegacyNode($src->documentElement, false);
    echo "bad-legacy\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'DOMNode') ? "reject-living\n" : "other2\n";
}

$html = Dom\HTMLDocument::createEmpty();
$lh = new DOMDocument();
$p = $lh->createElement('p');
$lh->appendChild($p);
$h = $html->importLegacyNode($p, true);
echo get_class($h), "\n";
?>
--EXPECT--
Dom\Element
isa
root:urn:x:t
Dom\Element
reject-legacy
reject-living
Dom\HTMLElement
