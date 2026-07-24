--TEST--
ext/dom DOMXPath::registerPhpFunctions() — php:function / functionString (#19331/#19709/#22719, ext/dom/xpath.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a>hi</a></r>');
$xp = new DOMXPath($doc);
echo 'has=', method_exists($xp, 'registerPhpFunctions') ? '1' : '0', "\n";
$xp->registerNamespace('php', 'http://php.net/xpath');
$xp->registerPhpFunctions('strtoupper');
var_export($xp->evaluate('php:function("strtoupper", string(//a))'));
echo "\n";
// Absolute path string(/r/a) — was empty before #19709 (evaluateAbsolutePath started at documentElement).
var_export($xp->evaluate('php:functionString("strtoupper", string(/r/a))'));
echo "\n";
// Nested string(php:functionString(...)) — was '' before #22719 (evaluateToMixed skipped php:function*).
var_export($xp->evaluate('string(php:functionString("strtoupper", //a))'));
echo "\n";
// php:function node-set args are DOMNode[] — strtoupper TypeErrors like Zend.
try {
    $xp->evaluate('php:function("strtoupper", //a)');
    echo "no-throw\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
?>
--EXPECT--
has=1
'HI'
'HI'
'HI'
TypeError
