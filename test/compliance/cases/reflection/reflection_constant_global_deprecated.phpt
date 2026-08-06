--TEST--
ReflectionConstant global isDeprecated + getAttributes (#21255, #28156, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.5
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::advertisesReflectionConstantClass()) {
    die('skip ReflectionConstant requires forward profile');
}
if (!PHPCompiler\CompilerVersion::supportsGlobalDeprecatedConstAttributes()) {
    die('skip global deprecated constants require PHP_COMPILER_PROFILE=8.5');
}
?>
--FILE--
<?php
const PLAIN_21255 = 2;
#[\Deprecated(message: 'old', since: '8.4')]
const OLD_21255 = 1;

$p = new ReflectionConstant('PLAIN_21255');
$o = new ReflectionConstant('OLD_21255');

echo $p->getName(), '=', $p->getValue(), "\n";
var_export($p->isDeprecated());
echo "\n";
var_export($o->isDeprecated());
echo "\n";
// Class-constant-only APIs must stay absent on ReflectionConstant (#28156).
echo 'getDeprecatedMessage=', method_exists($o, 'getDeprecatedMessage') ? '1' : '0', "\n";
echo 'getModifiers=', method_exists($p, 'getModifiers') ? '1' : '0', "\n";
var_export($p->getAttributes());
echo "\n";

class C21255 { public const FOO = 7; }
$c = new ReflectionConstant(C21255::class, 'FOO');
var_export($c->isDeprecated());
echo "\n";
$rcc = new ReflectionClassConstant(C21255::class, 'FOO');
var_export($rcc->isPublic());
echo "\n";
--EXPECT--
PLAIN_21255=2
false
true
getDeprecatedMessage=0
getModifiers=0
array (
)
false
true
