<?php
/**
 * After @$doc->loadXML(), property fetch as 2nd+ call arg must be the property value
 * (not loadXML's bool) — #21439 / lib/Compiler.php call-arg PropertyFetch wiring.
 */
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

if (class_exists('Dom\\HTMLDocument')) {
    $h = @Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body><div id="x">hi</div></body></html>');
    $div = $h->getElementById('x');
    two('dom', $div->localName);
}
