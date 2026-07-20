--TEST--
Dom\HTMLElement tagName/nodeName uppercase; localName lowercase (#21558)
--SKIPIF--
<?php
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#21558)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(static fn () => true);
$html = Dom\HTMLDocument::createFromString('<html><body><div id="d"></div></body></html>');
$d = $html->getElementById('d');
echo 'tag=', $d->tagName, "\n";
echo 'node=', $d->nodeName, "\n";
echo 'local=', $d->localName, "\n";
echo 'body=', $html->body->tagName, "\n";

$created = $html->createElement('SPAN');
echo 'created_tag=', $created->tagName, ' local=', $created->localName, "\n";

$svg = $html->createElementNS('http://www.w3.org/2000/svg', 'svg');
echo 'svg=', $svg->tagName, "\n";

$legacy = new DOMDocument();
$legacy->loadHTML('<div id="x"></div>', LIBXML_NOERROR);
$leg = $legacy->getElementById('x');
echo 'legacy=', $leg->tagName, "\n";

$xd = Dom\XMLDocument::createFromString('<root><div/></root>');
$xr = $xd->documentElement->firstElementChild;
echo 'xml=', $xr->tagName, "\n";
?>
--EXPECT--
tag=DIV
node=DIV
local=div
body=BODY
created_tag=SPAN local=span
svg=svg
legacy=div
xml=div
