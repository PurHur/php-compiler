--TEST--
stdlib DOMNode nodeType/ownerDocument/nodeValue (#14417, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/></root>');
$root = $doc->documentElement;
echo $root->nodeType, "\n";
echo ($root->ownerDocument === $doc) ? "doc\n" : "other\n";
echo var_export($root->nodeValue, true), "\n";
$root->nodeValue = 'hello';
echo var_export($root->nodeValue, true), "\n";
echo $root->childNodes->length, "\n";
?>
--EXPECT--
1
doc
''
'hello'
1
