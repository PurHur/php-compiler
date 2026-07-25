--TEST--
Dom\Element::$innerHTML HTML serializer for HTMLDocument (php-src inner_html_mixin.c; #22773)
--SKIPIF--
<?php
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#22773)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$html = Dom\HTMLDocument::createFromString(
    '<!doctype html><p id="p"><i></i><br><img src="x"></p>',
    LIBXML_NOERROR
);
$p = $html->getElementById('p');
echo $p->innerHTML, "\n";

$i = $html->createElement('i');
echo 'outer_i=', $i->getOuterHTML(), "\n";
echo 'save_i=', $html->saveHtml($i), "\n";

$xml = Dom\XMLDocument::createFromString('<root><i/></root>');
echo 'xml=', $xml->documentElement->innerHTML, "\n";
?>
--EXPECT--
<i></i><br><img src="x">
outer_i=<i></i>
save_i=<i></i>
xml=<i/>
