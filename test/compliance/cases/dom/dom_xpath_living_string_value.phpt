--TEST--
Dom\XPath::evaluate string()/number() uses textContent not null nodeValue (#21271)
--SKIPIF--
<?php
if (!class_exists('Dom\\XMLDocument')) {
    die('skip Dom\\XMLDocument requires PHP_COMPILER_PROFILE=8.4 (#21271)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$xml = '<r xmlns:n="urn:x"><n:a>1</n:a><b>2</b></r>';
$doc = Dom\XMLDocument::createFromString($xml);
$xp = new Dom\XPath($doc);
$xp->registerNamespace('n', 'urn:x');

echo 'nodeValue=', var_export($doc->documentElement->firstChild->nodeValue, true), "\n";
echo 'textContent=', var_export($doc->documentElement->firstChild->textContent, true), "\n";
echo 'string_na=', var_export($xp->evaluate('string(//n:a)'), true), "\n";
echo 'string_b=', var_export($xp->evaluate('string(//b)'), true), "\n";
echo 'number_na=', var_export($xp->evaluate('number(//n:a)'), true), "\n";
echo 'sum_b=', var_export($xp->evaluate('sum(//b)'), true), "\n";
echo 'bool_na=', var_export($xp->evaluate('boolean(//n:a)'), true), "\n";

$legacy = new DOMDocument();
$legacy->loadXML($xml);
$lxp = new DOMXPath($legacy);
$lxp->registerNamespace('n', 'urn:x');
echo 'legacy_string_na=', var_export($lxp->evaluate('string(//n:a)'), true), "\n";
--EXPECT--
nodeValue=NULL
textContent='1'
string_na='1'
string_b='2'
number_na=1.0
sum_b=2.0
bool_na=true
legacy_string_na='1'
