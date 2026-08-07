--TEST--
AOT: ReflectionParameter::isSensitiveParameter() phantom (skipped; never in php-src) (#16130, #28528)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsReflectionParameterIsSensitiveParameter()) {
    die('skip ReflectionParameter::isSensitiveParameter absent from php-src (#28528)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
function sens(#[\SensitiveParameter] string $x): void {}
$r = new ReflectionParameter('sens', 0);
echo method_exists($r, 'isSensitiveParameter') ? '1' : '0';
echo $r->isSensitiveParameter() ? '1' : '0';
function plain(string $x): void {}
$p = new ReflectionParameter('plain', 0);
echo $p->isSensitiveParameter() ? '1' : '0';
?>
--EXPECT--
110
