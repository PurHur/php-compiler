--TEST--
stdlib DOMNode textContent/previousSibling (#14419, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/></root>');
$a = $doc->getElementsByTagName('a')->item(0);
$b = $doc->getElementsByTagName('b')->item(0);
$root = $doc->documentElement;
echo ($b->previousSibling === $a) ? "prev\n" : "noprev\n";
echo null === $a->previousSibling ? "nullprev\n" : "badprev\n";
$root->textContent = 'hi';
echo var_export($root->textContent, true), "\n";
echo $root->childNodes->length, "\n";
?>
--EXPECT--
prev
nullprev
'hi'
1
