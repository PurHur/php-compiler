--TEST--
stdlib DOMNode::normalize() — adjacent text merge (ext/dom/node.c; #14395)
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$root->append('hello', ' world');
$root->normalize();
echo $root->textContent, "\n";
echo $root->childNodes->length, "\n";
?>
--EXPECT--
hello world
1
