--TEST--
stdlib DOMNode::append()/prepend() living-standard tree ops (#14380, ext/dom/element.c)
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$a = $doc->createElement('a');
$b = $doc->createElement('b');
$root->append($a, $b);
echo $root->firstChild->nodeName, "\n";
echo $root->lastChild->nodeName, "\n";
$root2 = $doc->createElement('r2');
$doc->appendChild($root2);
$x = $doc->createElement('x');
$y = $doc->createElement('y');
$root2->prepend($x, $y);
echo $root2->firstChild->nodeName, "\n";
echo $root2->lastChild->nodeName, "\n";
?>
--EXPECT--
a
b
x
y
