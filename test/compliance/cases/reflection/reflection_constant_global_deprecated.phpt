--TEST--
ReflectionConstant global isDeprecated / type / modifiers (#21255, #26308, ext/reflection/php_reflection.c)
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
var_export($o->getDeprecatedMessage());
echo "\n";
var_export($o->getDeprecatedVersion());
echo "\n";
var_export($p->getType());
echo "\n";
var_export($p->hasType());
echo "\n";
var_export($p->getModifiers());
echo "\n";
var_export($p->isFinal());
echo "\n";
var_export($p->isEnumCase());
echo "\n";
var_export($p->isPublic());
echo "\n";
var_export($p->isProtected());
echo "\n";
var_export($p->isPrivate());
echo "\n";
var_export($p->getAttributes());
echo "\n";

class C21255 { public const FOO = 7; }
$c = new ReflectionConstant(C21255::class, 'FOO');
var_export($c->isDeprecated());
echo "\n";
var_export($c->isPublic());
echo "\n";
--EXPECT--
PLAIN_21255=2
false
true
'old'
'8.4'
NULL
false
1
false
false
true
false
false
array (
)
false
true
