--TEST--
dom DOMDocument::loadHTML() HTML5 void tags do not collapse the tree (#20199)
--FILE--
<?php
$cases = [
    'meta' => '<html><head><meta charset="utf-8"></head><body><div id="d">x</div></body></html>',
    'base' => '<html><head><base href="http://ex/dir/"></head><body><div id="d">x</div></body></html>',
    'br' => '<html><body>a<br>b<div id="d">x</div></body></html>',
    'img' => '<html><body><img src="x"><div id="d">x</div></body></html>',
];
foreach ($cases as $name => $html) {
    $doc = new DOMDocument();
    $doc->loadHTML($html);
    $el = $doc->getElementById('d');
    echo $name, '=', null !== $el ? $el->textContent : 'null', "\n";
}
$doc = new DOMDocument();
$doc->loadHTML('<html><head><base href="http://ex/dir/"></head><body><div id="d">x</div></body></html>');
echo 'bases=', $doc->getElementsByTagName('base')->length, "\n";
echo 'base_href=', $doc->getElementsByTagName('base')->item(0)->getAttribute('href'), "\n";
--EXPECT--
meta=x
base=x
br=x
img=x
bases=1
base_href=http://ex/dir/
