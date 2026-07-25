--TEST--
stdlib DOMElement::getInnerHTML()/getOuterHTML() — PHP 8.4 profile (#16916, ext/dom/inner_html_mixin.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadHTML('<div id="x"><b>hi</b><i>!</i></div>');
$el = $doc->getElementById('x');
echo $el->getInnerHTML(), "\n";
echo $el->getOuterHTML(), "\n";
$empty = $doc->createElement('span');
echo $empty->getInnerHTML(), "\n";
echo $empty->getOuterHTML(), "\n";
?>
--EXPECT--
<b>hi</b><i>!</i>
<div id="x"><b>hi</b><i>!</i></div>

<span></span>
