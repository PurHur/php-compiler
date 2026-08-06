--TEST--
stdlib Dom\Element::getElementsByClassName() — PHP 8.5+ profile (#27593, ext/dom/php_dom.stub.php)
--SKIPIF--
<?php
putenv('PHP_COMPILER_PROFILE=8.5');
if (!\PHPCompiler\CompilerVersion::supportsDomElementGetElementsByClassName()) {
    die('skip Dom\\Element::getElementsByClassName requires PHP_COMPILER_PROFILE=8.5 (#27593)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
$doc = Dom\HTMLDocument::createFromString(
    '<html><body><div class="c"><span class="c x">a</span><p class="y">b</p></div></body></html>',
    LIBXML_NOERROR
);
$root = $doc->documentElement;
echo method_exists(Dom\Element::class, 'getElementsByClassName') ? "has\n" : "miss\n";
$list = $root->getElementsByClassName('c');
echo 'count=', $list->count(), "\n";
echo 'len=', $list->length, "\n";
echo 'item0=', $list->item(0)->tagName, "\n";
$fromDoc = $doc->getElementsByClassName('c');
echo 'doc_count=', $fromDoc->count(), "\n";
?>
--EXPECT--
has
count=2
len=2
item0=DIV
doc_count=2
