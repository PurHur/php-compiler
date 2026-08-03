--TEST--
stdlib BcMath\Number::__construct Reflection num string|int (#24626)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

use BcMath\Number;

if (!class_exists(Number::class, false)) {
    echo "skip: BcMath\\Number missing\n";
    exit(0);
}

$r = new ReflectionMethod(Number::class, '__construct');
echo 'arity=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo '$', $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'NONE',
        ' opt=', $p->isOptional() ? '1' : '0', "\n";
}
try {
    new Number(new stdClass());
    echo "stdClass=UNEXPECTED\n";
} catch (TypeError $e) {
    echo 'stdClass:', $e->getMessage(), "\n";
}
$n = new Number(num: '1');
echo 'named=', (string) $n, "\n";
--EXPECT--
arity=1
$num:string|int opt=0
stdClass:BcMath\Number::__construct(): Argument #1 ($num) must be of type string|int, stdClass given
named=1
