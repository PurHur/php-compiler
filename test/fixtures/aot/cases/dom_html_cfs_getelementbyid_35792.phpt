--TEST--
AOT Dom\HTMLDocument::createFromString getElementById (#35792)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$html = '<!DOCTYPE html><html><body><div id="p">hi</div></body></html>';
$doc = Dom\HTMLDocument::createFromString($html);
$el = $doc->getElementById('p');
echo null === $el ? "null\n" : $el->tagName."\n".$el->textContent."\n";
echo null === $doc->getElementById('nope') ? "null\n" : "found\n";
--EXPECT--
DIV
hi
null
