<?php
/** Repro #19709 — php:functionString + absolute string(/r/a); php:function node-set args. */
$dom = new DOMDocument();
$dom->loadXML('<r><a>hi</a></r>');
$xp = new DOMXPath($dom);
$xp->registerNamespace('php', 'http://php.net/xpath');
$xp->registerPhpFunctions();
var_export($xp->evaluate('php:functionString("strtoupper", string(/r/a))'));
echo "\n";
var_export($xp->evaluate('php:function("strtoupper", string(/r/a))'));
echo "\n";
try {
    var_export($xp->evaluate('php:function("strtoupper", //a)'));
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
