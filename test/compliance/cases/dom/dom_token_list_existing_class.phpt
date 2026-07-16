--TEST--
ext/dom DOMTokenList add/remove/toggle/replace on loadXML class attr (#19605, ext/dom/token_list.c)
--SKIPIF--
<?php
if (!class_exists('DOMTokenList')) {
    die('skip DOMTokenList not advertised on PHP 8.2 reference profile (#19605, ext/dom/token_list.c)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><e class="a"/></root>');
$e = $doc->documentElement->firstChild;
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
