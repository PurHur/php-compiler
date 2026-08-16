--TEST--
stdlib pow/sqrt/fmod Reflection/named params (#23306, basic_functions.stub.php)
--FILE--
<?php
$rp = new ReflectionFunction('pow');
echo 'pow=';
foreach ($rp->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
var_export(pow(num: 2, exponent: 3));
echo "\n";
try {
    pow(base: 2, exponent: 3);
    echo "legacy pow ok\n";
} catch (Throwable $e) {
    echo 'legacy pow ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}

$rs = new ReflectionFunction('sqrt');
echo 'sqrt=';
foreach ($rs->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
var_export(sqrt(num: 9.0));
echo "\n";
try {
    sqrt(number: 9.0);
    echo "legacy sqrt ok\n";
} catch (Throwable $e) {
    echo 'legacy sqrt ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}

$rf = new ReflectionFunction('fmod');
echo 'fmod=';
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
var_export(fmod(num1: 5.5, num2: 2.0));
echo "\n";
try {
    fmod(x: 5.5, y: 2.0);
    echo "legacy fmod ok\n";
} catch (Throwable $e) {
    echo 'legacy fmod ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
pow=num,exponent,
8
legacy pow ERR=Error: Unknown named parameter $base
sqrt=num,
3.0
legacy sqrt ERR=Error: Unknown named parameter $number
fmod=num1,num2,
1.5
legacy fmod ERR=Error: Unknown named parameter $x
