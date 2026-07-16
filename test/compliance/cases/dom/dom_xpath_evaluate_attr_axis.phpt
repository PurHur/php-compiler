--TEST--
dom DOMXPath::evaluate string()/number() on @attr axis (#19352, ext/dom/xpath.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a id="1"/><a id="2"/><b>hello</b></r>');
$xp = new DOMXPath($doc);
foreach (['string(//a[1]/@id)', 'string(//@id)', 'number(//a/@id)', 'string(//b)'] as $e) {
    echo $e, ' => ', var_export($xp->evaluate($e), true), "\n";
}
--EXPECT--
string(//a[1]/@id) => '1'
string(//@id) => '1'
number(//a/@id) => 1.0
string(//b) => 'hello'
