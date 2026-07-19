<?php
// Repro #20856 — Dom\XMLDocument createFromString/File must yield Dom\Element (not DOMElement).
if (!class_exists('Dom\\XMLDocument')) {
    fwrite(STDERR, "skip: Dom\\XMLDocument requires PHP_COMPILER_PROFILE=8.4\n");
    exit(0);
}

$doc = Dom\XMLDocument::createFromString(
    '<?xml version="1.0"?><root xmlns:a="urn:a"><a:item id="i">1</a:item></root>'
);
$root = $doc->documentElement;
$child = $root->firstElementChild ?? $root->firstChild;
echo 'root=', get_class($root), "\n";
echo 'child=', get_class($child), "\n";
echo 'created=', get_class($doc->createElement('x')), "\n";

$path = sys_get_temp_dir() . '/phpc_dom_xml_elclass_' . getmypid() . '.xml';
file_put_contents($path, '<r><c/></r>');
$fileDoc = Dom\XMLDocument::createFromFile($path);
@unlink($path);
$fileRoot = $fileDoc->documentElement;
$fileChild = $fileRoot->firstChild;
echo 'file_root=', get_class($fileRoot), "\n";
echo 'file_child=', get_class($fileChild), "\n";

$legacy = new DOMDocument();
$legacy->loadXML('<r><c/></r>');
$legacyRoot = $legacy->documentElement;
echo 'legacy=', get_class($legacyRoot), "\n";

$ok = $root instanceof Dom\Element
    && $child instanceof Dom\Element
    && $fileRoot instanceof Dom\Element
    && $fileChild instanceof Dom\Element
    && $legacyRoot instanceof DOMElement
    && !($legacyRoot instanceof Dom\Element);
echo $ok ? "dom_xml_parse_element_class_ok=1\n" : "dom_xml_parse_element_class_ok=0\n";
