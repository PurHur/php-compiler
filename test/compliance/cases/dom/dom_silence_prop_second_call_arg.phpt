--TEST--
DOM property as 2nd call arg after @silence must not bind prior call return (#21439)
--FILE--
<?php
function two(string $label, mixed $v): void
{
    echo $label, '=', is_object($v) ? ('OBJ:'.get_class($v)) : var_export($v, true), "\n";
}
function one(mixed $v): void
{
    echo 'one=', is_object($v) ? ('OBJ:'.get_class($v)) : var_export($v, true), "\n";
}
$d = new DOMDocument();
@$d->loadXML('<r id="1"/>');
$el = $d->documentElement;
one($el->tagName);
two('two', $el->tagName);
$x = $el->tagName;
two('var', $x);
echo 'echo=', $el->tagName, "\n";
?>
--EXPECT--
one='r'
two='r'
var='r'
echo=r
