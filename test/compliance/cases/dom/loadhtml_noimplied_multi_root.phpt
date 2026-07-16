--TEST--
DOMDocument::loadHTML() NOIMPLIED|NODEFDTD multi-root fragment nests under first (#19360, ext/dom/html_document.c)
--FILE--
<?php
$dom = new DOMDocument();
$ok = @$dom->loadHTML('<p>hi</p><p>yo</p>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
echo 'ok=', var_export($ok, true), "\n";
echo 'pcount=', $dom->getElementsByTagName('p')->length, "\n";
$html = $dom->saveHTML();
echo trim($html), "\n";
--EXPECT--
ok=true
pcount=2
<p>hi<p>yo</p></p>
