--TEST--
stdlib Dom\Element $innerHTML/$className/$id living props — PROFILE=8.4; $outerHTML undefined (#20532, #22482)
--SKIPIF--
<?php
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#22482)');
}
if (\PHPCompiler\CompilerVersion::supportsDomElementOuterHtmlProperty()) {
    die('skip $outerHTML advertised on PROFILE=8.5+ — see dom_element_outerhtml_85.phpt');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(function (int $n, string $m): bool {
    if (E_WARNING === $n || 2 === $n) {
        echo "ERR\n";
        return true;
    }
    return false;
});
$d = Dom\HTMLDocument::createFromString(
    '<div id="x" class="a"><span>hi</span></div>',
    LIBXML_NOERROR
);
$e = $d->getElementById('x');
echo get_class($e), "\n";
echo 'isset_inner=', (int) isset($e->innerHTML), ' empty_inner=', (int) empty($e->innerHTML), "\n";
echo 'inner=', $e->innerHTML, "\n";
echo 'isset_outer=', (int) isset($e->outerHTML), "\n";
$outer = $e->outerHTML;
echo 'outer_type=', get_debug_type($outer), "\n";
echo 'className=', $e->className, "\n";
echo 'id=', $e->id, "\n";

$e->className = 'b c';
echo 'className2=', $e->className, ' attr=', $e->getAttribute('class'), "\n";

$e->innerHTML = '<b>x</b><i>y</i>';
echo 'inner2=', $e->innerHTML, "\n";
echo 'child=', $e->firstChild !== null ? $e->firstChild->nodeName : 'NULL', "\n";
?>
--EXPECT--
Dom\HTMLElement
isset_inner=1 empty_inner=0
inner=<span>hi</span>
isset_outer=0
ERR
outer_type=null
className=a
id=x
className2=b c attr=b c
inner2=<b>x</b><i>y</i>
child=B
