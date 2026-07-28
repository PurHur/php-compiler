--TEST--
DOMXPath [@attr=N] numeric attribute predicates match Zend/libxml (#24333, ext/dom/xpath.c)
--SKIPIF--
<?php
if (!class_exists('DOMXPath', false)) {
    print "skip: DOMXPath not available\n";
}
?>
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a id="1">x</a><a id="2">y</a><a id="1.0">z</a><a id="01">w</a></r>');
$xp = new DOMXPath($doc);
echo 'eval=', var_export($xp->evaluate('string(//a[@id=1])'), true), "\n";
echo 'query=', $xp->query('//a[@id=1]')->length, "\n";
echo 'quoted_len=', $xp->query('//a[@id="1"]')->length, "\n";
echo 'quoted_str=', var_export($xp->evaluate('string(//a[@id="1"])'), true), "\n";
echo 'bool=', var_export($xp->evaluate('boolean(//a[@id=1])'), true), "\n";
echo 'count=', var_export($xp->evaluate('count(//a[@id=1])'), true), "\n";
echo 'attr_axis=', $xp->query('//a[@id=1]/@id')->length, "\n";
?>
--EXPECT--
eval='x'
query=3
quoted_len=1
quoted_str='x'
bool=true
count=3.0
attr_axis=3
