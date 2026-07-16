--TEST--
AOT: DOMXPath::evaluate string()/number() on //tag[n] (#19456)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a>1</a><a>2</a><b>hello</b></r>');
$x = new DOMXPath($d);
echo (int) $x->evaluate('count(//a)'), "\n";
echo $x->evaluate('string(//a[1])'), "\n";
echo $x->evaluate('string(//a[2])'), "\n";
echo (int) $x->evaluate('number(//a[2])'), "\n";
echo $x->evaluate('string(//b)'), "\n";
echo $x->evaluate('string(//a)'), "\n";
--EXPECT--
2
1
2
2
hello
1
