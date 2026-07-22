--TEST--
ReflectionParameter isDefaultValueConstant / getDefaultValueConstantName (#22026)
--FILE--
<?php
class C { const X = 5; }
function f($a = PHP_INT_MAX, $b = C::X, $c = 1, $d = [], $e = true) {}
foreach ((new ReflectionFunction('f'))->getParameters() as $p) {
    echo $p->getName(), ' ';
    echo $p->isDefaultValueConstant() ? 'const' : 'expr', ' ';
    var_export($p->getDefaultValueConstantName());
    echo "\n";
}
function g($x) {}
$p = (new ReflectionFunction('g'))->getParameters()[0];
try {
    $p->isDefaultValueConstant();
    echo "g_ok\n";
} catch (ReflectionException $e) {
    echo 'g: ', $e->getMessage(), "\n";
}
class T {
    const X = 1;
    public function m($a = T::X, $b = \C::X) {}
}
foreach ((new ReflectionMethod(T::class, 'm'))->getParameters() as $p) {
    echo 'T.', $p->getName(), ' ';
    echo $p->isDefaultValueConstant() ? 'const' : 'expr', ' ';
    var_export($p->getDefaultValueConstantName());
    echo "\n";
}
?>
--EXPECT--
a const 'PHP_INT_MAX'
b const 'C::X'
c expr NULL
d expr NULL
e expr NULL
g: Internal error: Failed to retrieve the default value
T.a const 'T::X'
T.b const 'C::X'
