--TEST--
DOMDocument::saveHTML document dump uses decimal NCRs for unnamed Unicode (#25547)
--FILE--
<?php
$doc = new DOMDocument();
@$doc->loadHTML('<p>&#x1F600;&#x4E2D;&#x100;&eacute;</p>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
$full = $doc->saveHTML();
$node = $doc->saveHTML($doc->documentElement);

$docAttr = new DOMDocument();
@$docAttr->loadHTML('<p title="&#x1F600; &#x4E2D;">x</p>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
$attrFull = $docAttr->saveHTML();
$attrNode = $docAttr->saveHTML($docAttr->documentElement);

$xml = new DOMDocument('1.0', 'UTF-8');
$xml->loadXML('<?xml version="1.0" encoding="UTF-8"?><p>&#x1F600;&#xE9;</p>');
$xmlFull = $xml->saveHTML();

echo str_contains($full, '&#128512;') ? 'emoji=decimal' : (str_contains($full, "\xF0\x9F\x98\x80") ? 'emoji=utf8' : 'emoji=other'), "\n";
echo str_contains($full, '&#20013;') ? 'cjk=decimal' : (str_contains($full, "\xE4\xB8\xAD") ? 'cjk=utf8' : 'cjk=other'), "\n";
echo str_contains($full, '&#256;') ? 'macron=decimal' : (str_contains($full, "\xC4\x80") ? 'macron=utf8' : 'macron=other'), "\n";
echo str_contains($full, '&eacute;') ? 'eacute=named' : 'eacute=other', "\n";
echo str_contains($node, "\xF0\x9F\x98\x80") && str_contains($node, "\xE4\xB8\xAD") ? 'node=utf8' : 'node=other', "\n";
echo str_contains($attrFull, 'title="&#128512; &#20013;"') ? 'attr_doc=decimal' : 'attr_doc=other', "\n";
echo str_contains($attrNode, "title=\"\xF0\x9F\x98\x80 \xE4\xB8\xAD\"") ? 'attr_node=utf8' : 'attr_node=other', "\n";
echo str_contains($xmlFull, '&#128512;') && str_contains($xmlFull, '&eacute;') ? 'xml_enc=decimal_named' : 'xml_enc=other', "\n";
--EXPECT--
emoji=decimal
cjk=decimal
macron=decimal
eacute=named
node=utf8
attr_doc=decimal
attr_node=utf8
xml_enc=decimal_named
