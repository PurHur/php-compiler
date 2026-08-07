--TEST--
ReflectionParameter::isSensitiveParameter() — phantom (skipped; never in php-src) (#16130, #28528)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsReflectionParameterIsSensitiveParameter()) {
    die('skip ReflectionParameter::isSensitiveParameter absent from php-src (#28528)');
}
?>
--FILE--
<?php
declare(strict_types=1);

function f(#[\SensitiveParameter] string $p) {}
function g(string $p) {}
$rf = new ReflectionParameter('f', 0);
$rg = new ReflectionParameter('g', 0);
echo 'exists: ', var_export(method_exists($rf, 'isSensitiveParameter'), true), "\n";
echo $rf->isSensitiveParameter() ? "yes\n" : "no\n";
echo $rg->isSensitiveParameter() ? "yes\n" : "no\n";

class C {
    public function m(#[\SensitiveParameter] string $secret, string $plain) {}
}
$rm = new ReflectionMethod(C::class, 'm');
$params = $rm->getParameters();
echo $params[0]->isSensitiveParameter() ? "yes\n" : "no\n";
echo $params[1]->isSensitiveParameter() ? "yes\n" : "no\n";
?>
--EXPECT--
exists: true
yes
no
yes
no
