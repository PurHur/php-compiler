<?php
/**
 * After loadXML with literal UTF-8 text, saveHTML must emit libxml numeric hex
 * character references (&#xE9;) for both node-scoped and document-wide dumps (#25208).
 * Distinct from loadHTML named-entity → UTF-8 node dump (#24152).
 */
$doc = new DOMDocument();
$doc->loadXML('<r a="café">café</r>');
$nodeHtml = $doc->saveHTML($doc->documentElement);
$docHtml = $doc->saveHTML();

echo 'node=' . (str_contains($nodeHtml, '&#xE9;') ? 'hex' : (str_contains($nodeHtml, '&eacute;') ? 'named' : (str_contains($nodeHtml, "\xC3\xA9") ? 'utf8' : 'other'))) . "\n";
echo 'doc=' . (str_contains($docHtml, '&#xE9;') ? 'hex' : (str_contains($docHtml, '&eacute;') ? 'named' : (str_contains($docHtml, "\xC3\xA9") ? 'utf8' : 'other'))) . "\n";
echo 'node_html=' . trim($nodeHtml) . "\n";
echo 'doc_html=' . trim($docHtml) . "\n";
