--TEST--
Stdlib: ReflectionParameter::isSensitive() — #[\SensitiveParameter] probe (#7072, #22899)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsReflectionParameterIsSensitiveParameter()) {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
function f(#[\SensitiveParameter] string $p) {}
function g(string $p) {}
$rf = new ReflectionParameter('f', 0);
$rg = new ReflectionParameter('g', 0);
var_export(method_exists($rf, 'isSensitive'));
echo "\n";
echo $rf->isSensitive() ? "yes\n" : "no\n";
echo $rg->isSensitive() ? "yes\n" : "no\n";

class C {
    public function m(#[\SensitiveParameter] string $secret, string $plain) {}
}
$rm = new ReflectionMethod(C::class, 'm');
$params = $rm->getParameters();
echo $params[0]->isSensitive() ? "yes\n" : "no\n";
echo $params[1]->isSensitive() ? "yes\n" : "no\n";
?>
--EXPECT--
true
yes
no
yes
no
