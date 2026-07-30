<?php
/**
 * When DOMDocument::$encoding is non-empty, saveHTML matches the HTML-doc split:
 * node dump keeps UTF-8; document dump uses named HTML entities (#25246).
 * Distinct from no-encoding loadXML → &#xHH; for both (#25208).
 */
$doc = new DOMDocument();
$doc->loadXML('<?xml version="1.0" encoding="UTF-8"?><r a="café">café</r>');

$nodeHtml = $doc->saveHTML($doc->documentElement);
$docHtml = $doc->saveHTML();

$classify = static function (string $h): string {
    if (str_contains($h, '&#xE9;')) {
        return 'hex';
    }
    if (str_contains($h, '&eacute;')) {
        return 'named';
    }
    if (str_contains($h, "\xC3\xA9")) {
        return 'utf8';
    }

    return 'other';
};

echo 'encoding=', var_export($doc->encoding, true), "\n";
echo 'node=', $classify($nodeHtml), "\n";
echo 'doc=', $classify($docHtml), "\n";
echo 'node_html=', trim($nodeHtml), "\n";
echo 'doc_html=', trim($docHtml), "\n";

// #25208 still: no encoding → numeric hex both dumps.
$bare = new DOMDocument();
$bare->loadXML('<r>café</r>');
$bareNode = $bare->saveHTML($bare->documentElement);
$bareDoc = $bare->saveHTML();
echo 'bare_node=', $classify($bareNode), "\n";
echo 'bare_doc=', $classify($bareDoc), "\n";
