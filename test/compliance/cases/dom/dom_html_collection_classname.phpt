--TEST--
Dom\HTMLCollection from getElementsByClassName — live length/item/namedItem (#20709, #27593)
--SKIPIF--
<?php
putenv('PHP_COMPILER_PROFILE=8.5');
if (!\PHPCompiler\CompilerVersion::supportsDomElementGetElementsByClassName()) {
    die('skip Dom\\Document::getElementsByClassName requires PHP_COMPILER_PROFILE=8.5 (#27593)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
echo class_exists('Dom\\HTMLCollection') ? "has\n" : "miss\n";
$doc = Dom\HTMLDocument::createFromString(
    '<html><body><div class="a" id="x">one</div><p class="a b" name="np">two</p></body></html>'
);
$list = $doc->getElementsByClassName('a');
echo get_class($list), "\n";
echo ($list instanceof Dom\HTMLCollection) ? "isa\n" : "not\n";
echo 'len=', $list->length, "\n";
echo 'item0=', $list->item(0)->tagName, "\n";
$named = $list->namedItem('x');
echo 'named=', $named ? $named->tagName : 'null', "\n";
echo 'dim=', isset($list['x']) ? $list['x']->tagName : 'null', "\n";

$el = $doc->body->appendChild($doc->createElement('span'));
$el->setAttribute('class', 'a');
echo 'live=', $list->length, "\n";

$legacy = new DOMDocument();
$legacy->loadHTML('<div class="a">z</div>', LIBXML_NOERROR);
$legacyList = $legacy->getElementsByTagName('div');
echo 'legacy=', get_class($legacyList), "\n";
?>
--EXPECT--
has
Dom\HTMLCollection
isa
len=2
item0=DIV
named=DIV
dim=DIV
live=3
legacy=DOMNodeList
