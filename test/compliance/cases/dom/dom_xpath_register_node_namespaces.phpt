--TEST--
DOMXPath registerNodeNamespaces default + in-scope prefixes (#20842)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r xmlns:p="urn:p"><child xmlns:q="urn:q"><q:e/><p:e/></child></r>');

$xp = new DOMXPath($doc);
echo 'prop=', $xp->registerNodeNamespaces ? '1' : '0', "\n";
echo 'doc=', get_class($xp->document), "\n";
$a = $xp->query('//p:e');
echo 'default_p=', ($a === false ? 'false' : (string) $a->length), "\n";
$b = @$xp->query('//q:e');
echo 'default_q=', ($b === false ? 'false' : (string) $b->length), "\n";
$child = $doc->documentElement->firstChild;
$c = $xp->query('//q:e', $child);
echo 'ctx_q=', ($c === false ? 'false' : (string) $c->length), "\n";
echo 'eval_count=', $xp->evaluate('count(//p:e)'), "\n";

$xpOff = new DOMXPath($doc, false);
echo 'ctor_prop=', $xpOff->registerNodeNamespaces ? '1' : '0', "\n";
$d = @$xpOff->query('//p:e');
echo 'ctor_false=', ($d === false ? 'false' : (string) $d->length), "\n";
$xpOff->registerNodeNamespaces = true;
echo 'prop_true=', $xpOff->query('//p:e')->length, "\n";
$xpOff->registerNodeNamespaces = false;
$f = @$xpOff->query('//p:e');
echo 'prop_false=', ($f === false ? 'false' : (string) $f->length), "\n";
$g = @$xp->query('//p:e', null, false);
echo 'arg_false=', ($g === false ? 'false' : (string) $g->length), "\n";
echo "ok\n";
?>
--EXPECT--
prop=1
doc=DOMDocument
default_p=1
default_q=false
ctx_q=1
eval_count=1
ctor_prop=0
ctor_false=false
prop_true=1
prop_false=false
arg_false=false
ok
