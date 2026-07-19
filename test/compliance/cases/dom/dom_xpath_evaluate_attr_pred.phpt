--TEST--
DOMXPath::evaluate() attribute predicates match query/Zend (#21148, ext/dom/xpath.c)
--SKIPIF--
<?php
if (!class_exists('DOMXPath', false)) {
    print "skip: DOMXPath not available\n";
}
?>
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a id="1">x</a><a id="2">y</a><a id="3">z</a></r>');
$xp = new DOMXPath($doc);
echo 'query=', $xp->query('//a[@id="2"]')->length, "\n";
$v = $xp->evaluate('//a[@id="2"]');
echo 'evaluate=', $v instanceof DOMNodeList ? $v->length : var_export($v, true), "\n";
echo 'string_elem=', var_export($xp->evaluate('string(/r/a[@id="2"])'), true), "\n";
echo 'string_attr=', var_export($xp->evaluate('string(//a[@id="2"]/@id)'), true), "\n";
echo 'count=', var_export($xp->evaluate('count(//a[@id="2"])'), true), "\n";
echo 'number=', var_export($xp->evaluate('number(/r/a[@id="2"]/@id)'), true), "\n";
echo 'cmp=', var_export($xp->evaluate('count(//a[@id="2"])=1'), true), "\n";
?>
--EXPECT--
query=1
evaluate=1
string_elem='y'
string_attr='2'
count=1.0
number=2.0
cmp=true
