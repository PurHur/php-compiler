--TEST--
DOMXPath or/and predicates and evaluate() (#32050, ext/dom/xpath.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a id="1" class="c">one</a><a id="2">two</a><b class="x">three</b></r>');
$xp = new DOMXPath($d);
echo 'or=', $xp->query('//a[@id or @class]')->length, "\n";
echo 'and=', $xp->query('//*[@id and @class]')->length, "\n";
echo 'eq_or=', $xp->query('//a[@id=1 or @id=2]')->length, "\n";
echo 'not_id=', $xp->query('//*[not(@id)]')->length, "\n";
echo 'eval_or=', var_export($xp->evaluate('true() or false()'), true), "\n";
echo 'eval_and=', var_export($xp->evaluate('true() and false()'), true), "\n";
echo 'num_or=', var_export($xp->evaluate('1 or 0'), true), "\n";
echo 'count=', var_export($xp->evaluate('count(//a[@id or @class])'), true), "\n";
echo 'starts=', $xp->query('//*[starts-with(@id,"1") or starts-with(@class,"x")]')->length, "\n";
echo 'path_or=', var_export($xp->evaluate('//a or //b'), true), "\n";
echo 'query_path_or=', $xp->query('//a or //b')->length, "\n";
?>
--EXPECT--
or=2
and=1
eq_or=2
not_id=2
eval_or=true
eval_and=false
num_or=true
count=2.0
starts=2
path_or=true
query_path_or=0
