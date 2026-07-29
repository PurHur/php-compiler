--TEST--
Dom\TokenList / DOMTokenList $value write + __toString (#24545, ext/dom/token_list.c)
--SKIPIF--
<?php
if (!class_exists('Dom\\TokenList') || !class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\TokenList / Dom\\HTMLDocument require PHP_COMPILER_PROFILE=8.4 (#24545)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$html = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div class="a b" id="d"></div></body></html>'
);
$cl = $html->getElementById('d')->classList;
echo get_class($cl), "\n";
echo $cl->value, "\n";
$cl->value = 'c d';
echo $cl->value, "\n";
echo $html->getElementById('d')->getAttribute('class'), "\n";
echo (int) method_exists($cl, '__toString'), "\n";
echo (string) $cl, "\n";

$dom = new DOMDocument();
$dom->loadXML('<root><e class="a b"/></root>');
$legacy = $dom->documentElement->firstChild->classList;
echo get_class($legacy), "\n";
$legacy->value = 'x y';
echo $legacy->value, "\n";
echo $dom->documentElement->firstChild->getAttribute('class'), "\n";
echo (string) $legacy, "\n";
?>
--EXPECT--
Dom\TokenList
a b
c d
c d
1
c d
DOMTokenList
x y
x y
x y
