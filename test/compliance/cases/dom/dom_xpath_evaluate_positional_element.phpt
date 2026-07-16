--TEST--
dom DOMXPath::evaluate string()/number() on //tag[n] element preds (#19456, ext/dom/xpath.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a>1</a><a>2</a><b>hello</b></r>');
$xp = new DOMXPath($doc);
foreach (['count(//a)', 'string(//a[1])', 'string(//a[2])', 'number(//a[2])', 'string(//b)', 'string(//a)'] as $e) {
    echo $e, ' => ', var_export($xp->evaluate($e), true), "\n";
}
--EXPECT--
count(//a) => 2.0
string(//a[1]) => '1'
string(//a[2]) => '2'
number(//a[2]) => 2.0
string(//b) => 'hello'
string(//a) => '1'
