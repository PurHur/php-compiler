--TEST--
DOMDocument::saveHTML with encoding=UTF-8 — node UTF-8 / doc named entities (#25246)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<?xml version="1.0" encoding="UTF-8"?><r a="café">café</r>');
$nodeHtml = $doc->saveHTML($doc->documentElement);
$docHtml = $doc->saveHTML();

echo str_contains($nodeHtml, "\xC3\xA9") ? 'node=utf8' : (str_contains($nodeHtml, '&#xE9;') ? 'node=hex' : (str_contains($nodeHtml, '&eacute;') ? 'node=named' : 'node=other')), "\n";
echo str_contains($docHtml, '&eacute;') ? 'doc=named' : (str_contains($docHtml, '&#xE9;') ? 'doc=hex' : (str_contains($docHtml, "\xC3\xA9") ? 'doc=utf8' : 'doc=other')), "\n";
echo 'node_html=', trim($nodeHtml), "\n";
echo 'doc_html=', trim($docHtml), "\n";

// #25208: no encoding still uses numeric hex for both dumps.
$bare = new DOMDocument();
$bare->loadXML('<r a="café">café</r>');
$bareNode = $bare->saveHTML($bare->documentElement);
$bareDoc = $bare->saveHTML();
echo str_contains($bareNode, '&#xE9;') ? 'bare_node=hex' : 'bare_node=other', "\n";
echo str_contains($bareDoc, '&#xE9;') ? 'bare_doc=hex' : 'bare_doc=other', "\n";

// Ctor encoding path.
$ctor = new DOMDocument('1.0', 'UTF-8');
$r = $ctor->createElement('r');
$ctor->appendChild($r);
$r->appendChild($ctor->createTextNode('café'));
echo str_contains($ctor->saveHTML($r), "\xC3\xA9") ? 'ctor_node=utf8' : 'ctor_node=other', "\n";
echo str_contains($ctor->saveHTML(), '&eacute;') ? 'ctor_doc=named' : 'ctor_doc=other', "\n";
--EXPECT--
node=utf8
doc=named
node_html=<r a="café">café</r>
doc_html=<r a="caf&eacute;">caf&eacute;</r>
bare_node=hex
bare_doc=hex
ctor_node=utf8
ctor_doc=named
