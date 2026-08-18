--TEST--
AOT: DOMXPath or/and predicates — query lengths (#32050)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a id="1" class="c">one</a><a id="2">two</a><b class="x">three</b></r>');
$xp = new DOMXPath($d);
echo 'or=', $xp->query('//a[@id or @class]')->length, "\n";
echo 'and=', $xp->query('//*[@id and @class]')->length, "\n";
echo 'eq_or=', $xp->query('//a[@id=1 or @id=2]')->length, "\n";
echo 'not_id=', $xp->query('//*[not(@id)]')->length, "\n";
echo 'starts=', $xp->query('//*[starts-with(@id,"1") or starts-with(@class,"x")]')->length, "\n";
echo 'query_path_or=', $xp->query('//a or //b')->length, "\n";
?>
--EXPECT--
or=2
and=1
eq_or=2
not_id=2
starts=2
query_path_or=0
