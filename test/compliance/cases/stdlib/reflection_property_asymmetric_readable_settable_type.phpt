--TEST--
ReflectionProperty::getType()/getSettableType() asymmetric typed property (#7053, #9873, #28532)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsAsymmetricVisibility()) {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
declare(strict_types=1);

class C {
    public (private(set)) int $x = 1;
    public (private(set)) string $p = 'x';
    public int $plain = 0;
}

$p = new ReflectionProperty(C::class, 'x');
echo 'x_type=', (string) $p->getType(), "\n";
echo 'x_settable=', (string) $p->getSettableType(), "\n";

$q = new ReflectionProperty(C::class, 'p');
echo 'p_type=', (string) $q->getType(), "\n";
echo 'p_settable=', (string) $q->getSettableType(), "\n";

$plain = new ReflectionProperty(C::class, 'plain');
echo 'plain_type=', (string) $plain->getType(), "\n";
echo 'plain_settable=', (string) $plain->getSettableType(), "\n";
--EXPECT--
x_type=int
x_settable=int
p_type=string
p_settable=string
plain_type=int
plain_settable=int
