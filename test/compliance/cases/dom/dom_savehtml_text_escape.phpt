--TEST--
ext/dom DOMDocument::saveHTML() escapes text &<> (attrs ok; script/style raw) (#21149)
--FILE--
<?php
declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadHTML(
    '<html><body><p>Hi &amp; bye</p></body></html>',
    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
);
$p = $doc->getElementsByTagName('p')->item(0);
echo 'p=', $doc->saveHTML($p), "\n";
echo 'text=', $p->textContent, "\n";

$div = $doc->createElement('div');
$div->setAttribute('data-x', 'a&b');
$div->appendChild($doc->createTextNode('x<y>z'));
echo 'div=', $doc->saveHTML($div), "\n";

$script = $doc->createElement('script');
$script->appendChild($doc->createTextNode('a&&b <c>'));
echo 'script=', $doc->saveHTML($script), "\n";

$style = $doc->createElement('style');
$style->appendChild($doc->createTextNode('a>b & c'));
echo 'style=', $doc->saveHTML($style), "\n";

$xmp = $doc->createElement('xmp');
$xmp->appendChild($doc->createTextNode('a<b>&c'));
echo 'xmp=', $doc->saveHTML($xmp), "\n";
?>
--EXPECT--
p=<p>Hi &amp; bye</p>
text=Hi & bye
div=<div data-x="a&amp;b">x&lt;y&gt;z</div>
script=<script>a&&b <c></script>
style=<style>a>b & c</style>
xmp=<xmp>a&lt;b&gt;&amp;c</xmp>
