--TEST--
stdlib DOMNode::before()/after()/replaceWith()/remove() (#15345, #15397, #15398, ext/dom/php_dom.c)
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

$doc2 = new DOMDocument();
$p = $doc2->createElement('p');
$doc2->appendChild($p);
$span = $doc2->createElement('span');
$p->after($span);
echo preg_replace('/\s+/', '', $doc2->saveXML()), "\n";
$frag = $doc2->createDocumentFragment();
$frag->appendChild($doc2->createElement('a'));
$p->after($frag);
echo preg_replace('/\s+/', '', $doc2->saveXML()), "\n";
$names = [];
foreach ($doc2->childNodes as $n) {
    $names[] = $n->nodeName;
}
echo implode(',', $names), "\n";
?>
--EXPECT--
<root><a/><before/><b/></root>
<root><a/><before/><b/><after/></root>
<root><a/><before/><replace/><after/></root>
<root><a/><before/><after/></root>
<?xmlversion="1.0"?><p/><span/>
<?xmlversion="1.0"?><p/><a/><span/>
p,a,span
