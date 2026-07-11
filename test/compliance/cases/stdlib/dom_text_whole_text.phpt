--TEST--
stdlib DOMText::wholeText adjacent text merge (#17527, ext/dom/text.c)
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$text = $doc->createTextNode('hello');
$root->appendChild($text);
$tail = $text->splitText(2);
echo $text->wholeText, "\n";
echo $tail->wholeText, "\n";
$root->appendChild($doc->createTextNode('world'));
echo $root->firstChild->wholeText, "\n";
$detached = $doc->createTextNode('solo');
echo $detached->wholeText, "\n";
$cdata = $doc->createCDATASection('x');
$root->appendChild($cdata);
echo $cdata->wholeText, "\n";
?>
--EXPECT--
hello
hello
helloworld
solo
helloworldx
