--TEST--
ext/dom Dom\HTMLElement::$classList / Dom\TokenList — PHP 8.4 forward profile (#19188, #28227, ext/dom/token_list.c)
--SKIPIF--
<?php
if (!class_exists('Dom\\TokenList') || !class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\TokenList / Dom\\HTMLDocument require PHP_COMPILER_PROFILE=8.4 (#19188)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$html = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div id="d"></div></body></html>'
);
$el = $html->getElementById('d');
$el->classList->add('one', 'two');
echo $el->getAttribute('class'), "\n";
echo (int) $el->classList->contains('one'), "\n";
echo $el->classList->length, "\n";
echo $el->classList->item(0), "\n";
echo (int) $el->classList->toggle('three'), "\n";
echo $el->getAttribute('class'), "\n";
$el->classList->remove('two');
echo $el->getAttribute('class'), "\n";
?>
--EXPECT--
one two
1
2
one
1
one two three
one three
