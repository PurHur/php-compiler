--TEST--
stdlib Dom\Element $innerHTML/$outerHTML/$className living props — PHP 8.4 (#20532, ext/dom/php_dom.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$d = Dom\HTMLDocument::createFromString('<div id="x" class="a"><span>hi</span></div>');
$e = $d->getElementById('x');
echo get_class($e), "\n";
echo 'isset_inner=', (int) isset($e->innerHTML), ' empty_inner=', (int) empty($e->innerHTML), "\n";
echo 'inner=', $e->innerHTML, "\n";
echo 'outer=', $e->outerHTML, "\n";
echo 'className=', $e->className, "\n";
echo 'id=', $e->id, "\n";

$e->className = 'b c';
echo 'className2=', $e->className, ' attr=', $e->getAttribute('class'), "\n";

$e->innerHTML = '<b>x</b><i>y</i>';
echo 'inner2=', $e->innerHTML, "\n";
echo 'child=', $e->firstChild !== null ? $e->firstChild->nodeName : 'NULL', "\n";

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
isset_inner=1 empty_inner=0
inner=<span>hi</span>
outer=<div id="x" class="a"><span>hi</span></div>
className=a
id=x
className2=b c attr=b c
inner2=<b>x</b><i>y</i>
child=B
outer_replaced=<p id="z" class="q">z</p>
old_gone=yes
orphan_tag=SPAN
