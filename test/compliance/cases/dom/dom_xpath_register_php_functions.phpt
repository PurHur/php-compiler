--TEST--
ext/dom DOMXPath::registerPhpFunctions() — php:function / functionString (#19331/#19709/#22719/#22797, ext/dom/xpath.c)
--FILE--
<?php
function double_it($n) { return $n * 2; }
function xp_null() { return null; }
function xp_arr() { return ['x', 'y']; }
function ret_bool() { return true; }

$doc = new DOMDocument();
$doc->loadXML('<r><a>hi</a><n>3</n></r>');
$xp = new DOMXPath($doc);
echo 'has=', method_exists($xp, 'registerPhpFunctions') ? '1' : '0', "\n";
$xp->registerNamespace('php', 'http://php.net/xpath');
$xp->registerPhpFunctions(['strtoupper', 'double_it', 'xp_null', 'xp_arr', 'ret_bool']);
var_export($xp->evaluate('php:function("strtoupper", string(//a))'));
echo "\n";
// Absolute path string(/r/a) — was empty before #19709 (evaluateAbsolutePath started at documentElement).
var_export($xp->evaluate('php:functionString("strtoupper", string(/r/a))'));
echo "\n";
// Nested string(php:functionString(...)) — was '' before #22719 (evaluateToMixed skipped php:function*).
var_export($xp->evaluate('string(php:functionString("strtoupper", //a))'));
echo "\n";
// Numeric / null / array returns → convert_to_string (#22797 / #22814 / #22816).
$r = $xp->evaluate('php:function("double_it", number(/r/n))');
echo gettype($r), ':', var_export($r, true), "\n";
$r = $xp->evaluate('php:functionString("double_it", number(/r/n))');
echo gettype($r), ':', var_export($r, true), "\n";
$r = $xp->evaluate('php:function("xp_null")');
echo gettype($r), ':', var_export($r, true), "\n";
$r = @$xp->evaluate('php:function("xp_arr")');
echo gettype($r), ':', var_export($r, true), "\n";
// Bool stays bool (xpath_callbacks.c special-case).
$r = $xp->evaluate('php:function("ret_bool")');
echo gettype($r), ':', var_export($r, true), "\n";
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
string:'6'
string:'6'
string:''
string:'Array'
boolean:true
TypeError
