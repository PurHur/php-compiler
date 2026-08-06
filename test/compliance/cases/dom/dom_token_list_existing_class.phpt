--TEST--
ext/dom Dom\TokenList add/remove/toggle/replace on HTML class attr (#19605, #28227, ext/dom/token_list.c)
--SKIPIF--
<?php
if (!class_exists('Dom\\TokenList') || !class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\TokenList / Dom\\HTMLDocument require PHP_COMPILER_PROFILE=8.4 (#19605)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$html = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><e class="a" id="e"></e></body></html>'
);
$e = $html->getElementById('e');
echo $e->classList->length, "\n";
echo $e->classList->item(0), "\n";
$e->classList->add('b');
echo $e->getAttribute('class'), "\n";
echo (int) $e->classList->contains('b'), "\n";
echo (int) $e->classList->toggle('c'), "\n";
echo $e->getAttribute('class'), "\n";
echo (int) $e->classList->replace('b', 'd'), "\n";
echo $e->getAttribute('class'), "\n";
$e->classList->remove('a');
echo $e->getAttribute('class'), "\n";
?>
--EXPECT--
1
a
a b
1
1
a b c
1
a d c
d c
