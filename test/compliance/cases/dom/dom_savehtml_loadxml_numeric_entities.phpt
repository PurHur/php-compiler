--TEST--
DOMDocument::saveHTML after loadXML emits &#xHH; numeric entities (#25208)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r a="café">café</r>');
$nodeHtml = $doc->saveHTML($doc->documentElement);
$docHtml = $doc->saveHTML();

echo str_contains($nodeHtml, '&#xE9;') ? 'node=hex' : (str_contains($nodeHtml, '&eacute;') ? 'node=named' : (str_contains($nodeHtml, "\xC3\xA9") ? 'node=utf8' : 'node=other')), "\n";
echo str_contains($docHtml, '&#xE9;') ? 'doc=hex' : (str_contains($docHtml, '&eacute;') ? 'doc=named' : (str_contains($docHtml, "\xC3\xA9") ? 'doc=utf8' : 'doc=other')), "\n";
echo 'node_html=', trim($nodeHtml), "\n";
echo 'doc_html=', trim($docHtml), "\n";

// #24152 still: loadHTML named entity → UTF-8 on node dump, named on document dump.
$h = new DOMDocument();
@$h->loadHTML('<p>&eacute;x</p>');
$p = $h->getElementsByTagName('p')->item(0);
$nodeH = $h->saveHTML($p);
$docH = $h->saveHTML();
echo str_contains($nodeH, "\xC3\xA9") ? 'html_node=utf8' : (str_contains($nodeH, '&eacute;') ? 'html_node=entity' : 'html_node=other'), "\n";
echo str_contains($docH, '&eacute;') ? 'html_doc=entity' : 'html_doc=other', "\n";
--EXPECT--
node=hex
doc=hex
node_html=<r a="caf&#xE9;">caf&#xE9;</r>
doc_html=<r a="caf&#xE9;">caf&#xE9;</r>
html_node=utf8
html_doc=entity
