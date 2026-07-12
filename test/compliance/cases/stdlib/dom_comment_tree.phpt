--TEST--
stdlib DOMComment tree mutation (#17531, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$comment = $doc->createComment('note');
$doc->appendChild($comment);
echo get_class($doc->firstChild), "\n";
echo null === $doc->documentElement ? "null\n" : "set\n";
$root = $doc->createElement('r');
$doc->appendChild($root);
$child = $doc->createElement('c');
$root->appendChild($child);
$root->insertBefore($doc->createComment('note'), $child);
echo $doc->saveXML($root), "\n";
$fragDoc = new DOMDocument();
$frag = $fragDoc->createDocumentFragment();
$frag->appendChild($fragDoc->createComment('frag'));
echo get_class($frag->firstChild), "\n";
?>
--EXPECT--
DOMComment
null
<r><!--note--><c/></r>
DOMComment
