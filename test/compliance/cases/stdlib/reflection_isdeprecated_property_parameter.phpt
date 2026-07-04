--TEST--
ReflectionProperty/ReflectionParameter::isDeprecated() on #[Deprecated] members (#9768, ext/reflection/php_reflection.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsReflectionPropertyParameterIsDeprecated()) {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
declare(strict_types=1);

class DepProp {
    #[\Deprecated]
    public int $p = 0;
}
$rp = new ReflectionProperty(DepProp::class, 'p');
echo 'prop exists: ', var_export(method_exists($rp, 'isDeprecated'), true), "\n";
echo 'prop deprecated: ', var_export($rp->isDeprecated(), true), "\n";

function dep_param(#[\Deprecated] string $x): void {}
$rparam = new ReflectionParameter('dep_param', 'x');
echo 'param exists: ', var_export(method_exists($rparam, 'isDeprecated'), true), "\n";
echo 'param deprecated: ', var_export($rparam->isDeprecated(), true), "\n";

function plain_param(string $x): void {}
$plain = new ReflectionParameter('plain_param', 'x');
echo 'plain deprecated: ', var_export($plain->isDeprecated(), true), "\n";
--EXPECT--
prop exists: true
prop deprecated: true
param exists: true
param deprecated: true
plain deprecated: false
