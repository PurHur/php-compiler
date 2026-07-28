<?php
/**
 * DOMDocument::saveHTML($node) must emit UTF-8 for non-ASCII text (libxml htmlNodeDump),
 * while document-wide saveHTML() keeps HTML named entities (htmlDocDump / #23684).
 */
$doc = new DOMDocument();
@$doc->loadHTML('<p>&eacute;x&nbsp;y</p>');
$p = $doc->getElementsByTagName('p')->item(0);
$nodeHtml = $doc->saveHTML($p);
$fullHtml = $doc->saveHTML();

$doc2 = new DOMDocument();
@$doc2->loadHTML('<p>a&amp;b&lt;c</p>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
$ampHtml = $doc2->saveHTML($doc2->documentElement);

echo 'node_eacute=' . (str_contains($nodeHtml, '&eacute;') ? 'entity' : (str_contains($nodeHtml, "\xC3\xA9") ? 'utf8' : 'other')) . "\n";
echo 'node_nbsp=' . (str_contains($nodeHtml, '&nbsp;') ? 'entity' : (str_contains($nodeHtml, "\xC2\xA0") ? 'utf8' : 'other')) . "\n";
echo 'node_amp_lt=' . (str_contains($ampHtml, 'a&amp;b&lt;c') ? 'escaped' : 'other') . "\n";
echo 'full_eacute=' . (str_contains($fullHtml, '&eacute;') ? 'entity' : 'other') . "\n";
echo 'full_nbsp=' . (str_contains($fullHtml, '&nbsp;') ? 'entity' : 'other') . "\n";
echo 'node_html=' . $nodeHtml . "\n";
