<?php
/**
 * Document-wide DOMDocument::saveHTML() must emit decimal NCRs for codepoints
 * without an HTML 4.01 named entity (libxml htmlDocDump). Node-scoped dumps keep UTF-8.
 *
 * Zend: <p>&#128512;&#20013;</p> / node UTF-8
 * Bug:   <p>😀中</p> on document dump
 */
$d = new DOMDocument();
@$d->loadHTML('<p>&#x1F600;&#x4E2D;</p>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
echo 'doc=' . trim($d->saveHTML()) . "\n";
echo 'node=' . $d->saveHTML($d->documentElement) . "\n";
$d2 = new DOMDocument();
@$d2->loadHTML('<p title="&#x1F600;">x</p>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
echo 'attr_doc=' . trim($d2->saveHTML()) . "\n";
