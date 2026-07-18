--TEST--
stdlib Dom\TokenList — Dom\HTMLElement::$classList on PHP 8.4 profile (#20512, ext/dom/token_list.c)
--SKIPIF--
<?php
if (!class_exists('Dom\\TokenList') || !class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\TokenList / Dom\\HTMLDocument require PHP_COMPILER_PROFILE=8.4 (#20512)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo class_exists('Dom\\TokenList') ? '1' : '0', "\n";
echo class_exists('DOMTokenList') ? '1' : '0', "\n";
$html = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div class="a b" id="d"></div></body></html>'
);
$el = $html->getElementById('d');
echo get_class($el->classList), "\n";
echo (int) ($el->classList instanceof Dom\TokenList), "\n";
$el->classList->add('c');
echo $el->getAttribute('class'), "\n";
echo (int) $el->classList->contains('a'), "\n";
$el->classList->remove('b');
echo $el->getAttribute('class'), "\n";
echo (int) $el->classList->toggle('x'), "\n";
echo $el->getAttribute('class'), "\n";
// Legacy DOMElement keeps DOMTokenList
$dom = new DOMDocument();
$legacy = $dom->createElement('p');
$dom->appendChild($legacy);
echo get_class($legacy->classList), "\n";
?>
--EXPECT--
1
1
Dom\TokenList
1
a b c
1
a c
1
a c x
DOMTokenList
