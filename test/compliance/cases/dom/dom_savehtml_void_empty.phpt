--TEST--
ext/dom DOMDocument::saveHTML() HTML_EMPTY void + expand empties (#20625, re-#18668, ext/dom/php_dom.c)
--FILE--
<?php
declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadHTML('<p>a<br>b</p>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
echo $doc->saveHTML($doc->getElementsByTagName('br')->item(0)), "\n";
echo trim($doc->saveHTML()), "\n";

$doc2 = new DOMDocument();
$doc2->loadHTML('<div></div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
echo $doc2->saveHTML($doc2->getElementsByTagName('div')->item(0)), "\n";

$imgDoc = new DOMDocument();
$imgDoc->loadHTML('<img src="x">', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
echo $imgDoc->saveHTML($imgDoc->getElementsByTagName('img')->item(0)), "\n";

$doc3 = new DOMDocument();
$doc3->loadXML('<root><a/><br/></root>');
echo trim($doc3->saveHTML()), "\n";
--EXPECT--
<br>
<p>a<br>b</p>
<div></div>
<img src="x">
<root><a></a><br></root>
