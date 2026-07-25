<?php
// Zend convert_to_string on numeric php:function returns → string '6' (#22797).
function double_it($n) { return $n * 2; }
$d = new DOMDocument();
$d->loadXML('<r><n>3</n></r>');
$xp = new DOMXPath($d);
$xp->registerNamespace('php', 'http://php.net/xpath');
$xp->registerPhpFunctions('double_it');
$r = $xp->evaluate('php:function("double_it", number(/r/n))');
echo gettype($r), ':', var_export($r, true), "\n";
$r2 = $xp->evaluate('php:functionString("double_it", number(/r/n))');
echo gettype($r2), ':', var_export($r2, true), "\n";
