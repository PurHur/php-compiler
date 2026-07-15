--TEST--
stdlib DOMElement::$classList / DOMTokenList — PHP 8.4 profile (#16876, ext/dom/token_list.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
if (!class_exists('DOMTokenList')) {
    echo "skip: DOMTokenList not on profile\n";
    exit(0);
}
$dom = new DOMDocument();
$el = $dom->createElement('p');
$dom->appendChild($el);
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
