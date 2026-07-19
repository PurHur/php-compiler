--TEST--
Dom\ ParentNode::$children — live Dom\HTMLCollection (#21033)
--SKIPIF--
<?php
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#21033)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = Dom\HTMLDocument::createFromString(
    '<!doctype html><html><body><div id="a"><p>1</p><!--c--><span>2</span>text</div></body></html>',
    LIBXML_NOERROR
);
$el = $doc->getElementById('a');
echo 'isset=', isset($el->children) ? 'yes' : 'no', "\n";
echo 'type=', get_class($el->children), "\n";
echo 'isa=', ($el->children instanceof Dom\HTMLCollection) ? 'yes' : 'no', "\n";
echo 'len=', $el->children->length, "\n";
echo 'item0=', $el->children->item(0)->tagName, "\n";
echo 'item1=', $el->children->item(1)->tagName, "\n";
$el->appendChild($doc->createElement('b'));
echo 'live=', $el->children->length, "\n";

echo 'doc_isset=', isset($doc->children) ? 'yes' : 'no', "\n";
echo 'doc_type=', get_class($doc->children), "\n";
echo 'doc_len=', $doc->children->length, "\n";
echo 'doc_item0=', $doc->children->item(0)->tagName, "\n";

$frag = $doc->createDocumentFragment();
$frag->appendChild($doc->createElement('x'));
$frag->appendChild($doc->createTextNode('t'));
$frag->appendChild($doc->createElement('y'));
echo 'frag_isset=', isset($frag->children) ? 'yes' : 'no', "\n";
echo 'frag_type=', get_class($frag->children), "\n";
echo 'frag_len=', $frag->children->length, "\n";
echo 'frag_item0=', $frag->children->item(0)->tagName, "\n";
echo 'frag_item1=', $frag->children->item(1)->tagName, "\n";

$legacy = new DOMDocument();
$legacy->loadHTML('<div id="z"><p>1</p></div>', LIBXML_NOERROR);
$leg = $legacy->getElementById('z');
echo 'legacy_isset=', isset($leg->children) ? 'yes' : 'no', "\n";
?>
--EXPECT--
isset=yes
type=Dom\HTMLCollection
isa=yes
len=2
item0=p
item1=span
live=3
doc_isset=yes
doc_type=Dom\HTMLCollection
doc_len=1
doc_item0=html
frag_isset=yes
frag_type=Dom\HTMLCollection
frag_len=2
frag_item0=x
frag_item1=y
legacy_isset=no
