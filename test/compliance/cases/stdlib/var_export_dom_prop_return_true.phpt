--TEST--
stdlib: var_export($domProp, true) after @loadHTML matches Zend (#21975, ext/standard/var.c)
--FILE--
<?php
$d = new DOMDocument();
@$d->loadHTML('<p>hello</p>');
$p = $d->getElementsByTagName('p')->item(0);
$tn = $p->firstChild;
echo var_export($p->nodeName, true), "\n";
echo var_export($tn->data, true), "\n";
echo var_export($tn->textContent, true), "\n";
var_export($tn->data);
echo "\n";
$x = $tn->data;
echo var_export($x, true), "\n";
$o = new class {
    public string $s = 'hello';
};
echo var_export($o->s, true), "\n";
--EXPECT--
'p'
'hello'
'hello'
'hello'
'hello'
'hello'
