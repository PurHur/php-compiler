--TEST--
stdlib DOMNode::before()/after()/replaceWith()/remove() (#15345, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$a = $doc->createElement('a');
$b = $doc->createElement('b');
$root->appendChild($a);
$root->appendChild($b);
$before = $doc->createElement('before');
$b->before($before);
echo $doc->saveXML($root), "\n";
$after = $doc->createElement('after');
$b->after($after);
echo $doc->saveXML($root), "\n";
$repl = $doc->createElement('replace');
$b->replaceWith($repl);
echo $doc->saveXML($root), "\n";
$repl->remove();
echo $doc->saveXML($root), "\n";
?>
--EXPECT--
<root><a/><before/><b/></root>
<root><a/><before/><b/><after/></root>
<root><a/><before/><replace/><after/></root>
<root><a/><before/><after/></root>
