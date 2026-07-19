<?php
/**
 * DOMXPath defaults registerNodeNamespaces=true (php-src xpath.c).
 * Prefixed queries must resolve in-scope xmlns from the context node
 * (documentElement when context is omitted) without registerNamespace().
 */
$doc = new DOMDocument();
$doc->loadXML('<r xmlns:p="urn:p"><child xmlns:q="urn:q"><q:e/><p:e/></child></r>');

$xp = new DOMXPath($doc);
$a = $xp->query('//p:e');
echo 'default_p=' . ($a === false ? 'false' : (string) $a->length) . "\n";

$b = @$xp->query('//q:e');
echo 'default_q=' . ($b === false ? 'false' : (string) $b->length) . "\n";

$child = $doc->documentElement->firstChild;
$c = $xp->query('//q:e', $child);
echo 'ctx_q=' . ($c === false ? 'false' : (string) $c->length) . "\n";

$xpOff = new DOMXPath($doc, false);
$d = @$xpOff->query('//p:e');
echo 'ctor_false=' . ($d === false ? 'false' : (string) $d->length) . "\n";

$xpOff->registerNodeNamespaces = true;
$e = $xpOff->query('//p:e');
echo 'prop_true=' . ($e === false ? 'false' : (string) $e->length) . "\n";

$xpOff->registerNodeNamespaces = false;
$f = @$xpOff->query('//p:e');
echo 'prop_false=' . ($f === false ? 'false' : (string) $f->length) . "\n";

$g = @$xp->query('//p:e', null, false);
echo 'arg_false=' . ($g === false ? 'false' : (string) $g->length) . "\n";

echo "ok\n";
