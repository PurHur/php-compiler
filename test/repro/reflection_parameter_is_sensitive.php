<?php
function f(#[\SensitiveParameter] string $p) {}
function g(string $p) {}
$rf = new ReflectionParameter('f', 0);
$rg = new ReflectionParameter('g', 0);
var_export(method_exists($rf, 'isSensitive'));
echo "\n";
echo $rf->isSensitive() ? "yes\n" : "no\n";
echo $rg->isSensitive() ? "yes\n" : "no\n";
