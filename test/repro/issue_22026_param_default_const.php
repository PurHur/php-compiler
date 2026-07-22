<?php
class C { const X = 5; }
function f($a = PHP_INT_MAX, $b = C::X, $c = 1, $d = []) {}
$ps = (new ReflectionFunction('f'))->getParameters();
foreach ($ps as $p) {
    echo $p->getName(), ' ';
    try {
        echo $p->isDefaultValueConstant() ? 'const' : 'expr', ' ';
        var_export($p->getDefaultValueConstantName());
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
