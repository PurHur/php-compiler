--TEST--
DOMDocument::loadHTML() LIBXML_HTML_NOIMPLIED|NODEFDTD fragment without DOCTYPE (#19090, ext/dom/html_document.c)
--FILE--
<?php
$doc = new DOMDocument();
$flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;
$doc->loadHTML('<p>hi</p>', $flags);
echo trim($doc->saveHTML()), "\n";
--EXPECT--
<p>hi</p>
