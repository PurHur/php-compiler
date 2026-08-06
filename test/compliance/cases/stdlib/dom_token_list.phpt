--TEST--
stdlib Dom\HTMLElement::$classList / Dom\TokenList — PHP 8.4 profile (#16876, #28227, ext/dom/token_list.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
if (!class_exists('Dom\\TokenList') || !class_exists('Dom\\HTMLDocument')) {
    echo "skip: Dom\\TokenList not on profile\n";
    exit(0);
}
$html = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><p id="p"></p></body></html>'
);
$el = $html->getElementById('p');
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
