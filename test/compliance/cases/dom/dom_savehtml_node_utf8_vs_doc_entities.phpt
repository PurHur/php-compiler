--TEST--
DOMDocument::saveHTML($node) keeps UTF-8; document dump keeps named entities (#24152 / #23684)
--FILE--
<?php
$doc = new DOMDocument();
@$doc->loadHTML('<p>&eacute;x&nbsp;y</p>');
$p = $doc->getElementsByTagName('p')->item(0);
$nodeHtml = $doc->saveHTML($p);
$fullHtml = $doc->saveHTML();

$doc2 = new DOMDocument();
@$doc2->loadHTML('<p>a&amp;b&lt;c</p>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
$ampHtml = $doc2->saveHTML($doc2->documentElement);

echo str_contains($nodeHtml, '&eacute;') ? 'node_eacute=entity' : (str_contains($nodeHtml, "\xC3\xA9") ? 'node_eacute=utf8' : 'node_eacute=other'), "\n";
echo str_contains($nodeHtml, '&nbsp;') ? 'node_nbsp=entity' : (str_contains($nodeHtml, "\xC2\xA0") ? 'node_nbsp=utf8' : 'node_nbsp=other'), "\n";
echo str_contains($ampHtml, 'a&amp;b&lt;c') ? 'node_amp_lt=escaped' : 'node_amp_lt=other', "\n";
echo str_contains($fullHtml, '&eacute;') ? 'full_eacute=entity' : 'full_eacute=other', "\n";
echo str_contains($fullHtml, '&nbsp;') ? 'full_nbsp=entity' : 'full_nbsp=other', "\n";
--EXPECT--
node_eacute=utf8
node_nbsp=utf8
node_amp_lt=escaped
full_eacute=entity
full_nbsp=entity
