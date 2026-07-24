<?php
// Requires PHP_COMPILER_PROFILE=8.4 (default 8.2 profile withholds isSensitive — #22899).
// See also test/repro/reflection_parameter_is_sensitive_profile.php
function f(#[\SensitiveParameter] string $p) {}
function g(string $p) {}
$rf = new ReflectionParameter('f', 0);
$rg = new ReflectionParameter('g', 0);
var_export(method_exists($rf, 'isSensitive'));
echo "\n";
echo $rf->isSensitive() ? "yes\n" : "no\n";
echo $rg->isSensitive() ? "yes\n" : "no\n";
