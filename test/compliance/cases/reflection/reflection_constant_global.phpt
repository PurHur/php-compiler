--TEST--
ReflectionConstant global constant ctor — single-arg form (#17341, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
define('RC_GLOBAL_17341', 42);
$r = new ReflectionConstant('PHP_VERSION');
echo $r->getName(), "\n";
echo is_string($r->getValue()) ? 'string' : gettype($r->getValue()), "\n";
echo '' !== (string) $r->getValue() ? 'nonempty' : 'empty', "\n";

$u = new ReflectionConstant('RC_GLOBAL_17341');
var_export($u->getValue());
echo "\n";

class C17341 { public const FOO = 7; }
$c = new ReflectionConstant(C17341::class, 'FOO');
var_export($c->getName());
echo "\n";
var_export($c->getValue());
echo "\n";

try {
    new ReflectionConstant('DOES_NOT_EXIST_17341');
    echo "no throw\n";
} catch (ReflectionException $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
PHP_VERSION
string
nonempty
42
'FOO'
7
Constant "DOES_NOT_EXIST_17341" does not exist
