--TEST--
AOT: DOMXPath::query() self-closing [@attr] predicate (#28647)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$d->loadXML('<r><a id="1"/><a id="2"/></r>');
$x = new DOMXPath($d);
echo $x->query('//a[@id="1"]')->length, "\n";
echo $x->query('//a[@id="2"]')->length, "\n";
echo $x->query('//a[@id="9"]')->length, "\n";
echo $x->query('//a')->length, "\n";
--EXPECT--
1
1
0
2
