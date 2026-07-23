--TEST--
stdlib Dom\Element $outerHTML living prop — PROFILE=8.5 (#22482, re-#20532, ext/dom/php_dom.stub.php)
--SKIPIF--
<?php
putenv('PHP_COMPILER_PROFILE=8.5');
if (!\PHPCompiler\CompilerVersion::supportsDomElementOuterHtmlProperty()) {
    die('skip Dom\\Element::$outerHTML requires PHP_COMPILER_PROFILE=8.5 (#22482)');
}
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires living Dom namespace');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
$d = Dom\HTMLDocument::createFromString(
    '<div id="x" class="a"><span>hi</span></div>',
    LIBXML_NOERROR
);
$e = $d->getElementById('x');
echo get_class($e), "\n";
echo 'isset_outer=', (int) isset($e->outerHTML), "\n";
echo 'outer=', $e->outerHTML, "\n";

$parent = $e->parentNode;
$e->outerHTML = '<p id="z" class="q">z</p>';
$z = $d->getElementById('z');
echo 'outer_replaced=', ($z !== null ? $z->outerHTML : 'NULL'), "\n";
echo 'old_gone=', ($d->getElementById('x') === null ? 'yes' : 'no'), "\n";

$orphanDoc = Dom\HTMLDocument::createFromString('<span id="orphan">o</span>');
$orphan = $orphanDoc->getElementById('orphan');
$orphan->parentNode->removeChild($orphan);
$orphan->outerHTML = '<em>nope</em>';
echo 'orphan_tag=', $orphan->nodeName, "\n";
?>
--EXPECT--
Dom\HTMLElement
isset_outer=1
outer=<div id="x" class="a"><span>hi</span></div>
outer_replaced=<p id="z" class="q">z</p>
old_gone=yes
orphan_tag=SPAN
