--TEST--
Stdlib: ReflectionParameter::isSensitive() — phantom (skipped; never in php-src) (#7072, #22899, #28528)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsReflectionParameterIsSensitiveParameter()) {
    die('skip ReflectionParameter::isSensitive absent from php-src (#28528)');
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
