--TEST--
ext/dom DOMXPath::quote() — XPath literal escaping (#18650, ext/dom/xpath.c)
--SKIPIF--
<?php
if (!method_exists('DOMXPath', 'quote')) {
    die('skip DOMXPath::quote() not advertised on PHP 8.2 reference profile (#18650, ext/dom/xpath.c)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo DOMXPath::quote("'quoted' name"), "\n";
echo DOMXPath::quote("'different' \"quote\" styles"), "\n";
try {
    DOMXPath::quote("a\x00b");
    echo "no_throw\n";
} catch (ValueError $e) {
    echo str_contains($e->getMessage(), 'must not contain any null bytes') ? "null_byte_ok\n" : "null_byte_fail\n";
}
?>
--EXPECT--
"'quoted' name"
concat("'different' ",'"quote" styles')
null_byte_ok
