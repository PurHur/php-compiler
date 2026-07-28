<?php
function f($a = UNDEF_CONST_FOR_PARITY) { return $a; }
try {
    var_export(f());
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
const FOO = 123;
function g($a = FOO) { return $a; }
echo g(), "\n";
$p = (new ReflectionFunction('f'))->getParameters()[0];
echo $p->isDefaultValueAvailable() ? 'avail ' : 'noavail ';
echo $p->isDefaultValueConstant() ? 'const ' : 'expr ';
echo $p->getDefaultValueConstantName(), "\n";
try {
    var_export($p->getDefaultValue());
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
