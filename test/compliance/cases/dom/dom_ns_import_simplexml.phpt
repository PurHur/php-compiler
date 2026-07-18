--TEST--
Dom\import_simplexml() — namespaced SimpleXML bridge returns Dom\Element (#20711)
--SKIPIF--
<?php
if (!class_exists('Dom\\Element') || !function_exists('Dom\\import_simplexml')) {
    die('skip Dom\\import_simplexml requires PHP_COMPILER_PROFILE=8.4 (#20711)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('dom_import_simplexml') ? "legacy\n" : "no-legacy\n";
echo function_exists('Dom\\import_simplexml') ? "ns\n" : "no-ns\n";

$sxe = simplexml_load_string('<root><item id="1">a</item></root>');
$dom = Dom\import_simplexml($sxe);
echo get_class($dom), "\n";
echo ($dom instanceof Dom\Element) ? "isa\n" : "not\n";
echo $dom->nodeName, "\n";
echo $dom->getElementsByTagName('item')->item(0)->textContent, "\n";
echo $dom->getElementsByTagName('item')->item(0)->getAttribute('id'), "\n";

$dom->setAttribute('k', 'v');
echo (string) $sxe['k'], "\n";
?>
--EXPECT--
legacy
ns
Dom\Element
isa
root
a
1
v
