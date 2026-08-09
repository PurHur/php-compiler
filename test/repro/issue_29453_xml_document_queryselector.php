<?php
// #29453 Dom\XMLDocument::querySelector / querySelectorAll — Zend Dom\Document ParentNode
use Dom\XMLDocument;

$d = XMLDocument::createFromString('<r><a id="x"><b class="c">t</b></a><c/></r>');
echo 'has_qs=', method_exists($d, 'querySelector') ? '1' : '0', "\n";
echo 'has_qsa=', method_exists($d, 'querySelectorAll') ? '1' : '0', "\n";
$a = $d->querySelector('a');
echo 'qs=', $a ? $a->localName : 'null', "\n";
echo 'qsa=', $d->querySelectorAll('b')->length, "\n";
echo 'qsa2=', $d->querySelectorAll('a, c')->length, "\n";
$r = new ReflectionMethod(XMLDocument::class, 'querySelector');
echo 'decl=', $r->getDeclaringClass()->getName(), "\n";
try {
    $d->querySelector();
} catch (ArgumentCountError $e) {
    echo 'arity=', $e->getMessage(), "\n";
}
try {
    $d->querySelector([]);
} catch (TypeError $e) {
    echo 'type=', $e->getMessage(), "\n";
}
