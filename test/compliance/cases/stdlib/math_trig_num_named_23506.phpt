--TEST--
stdlib sin/cos/tan/asin/acos/atan/exp/log/log10 Reflection $num + named args (#23506, math.stub.php)
--FILE--
<?php
foreach (['sin', 'cos', 'tan', 'asin', 'acos', 'atan', 'exp', 'log10', 'log'] as $fn) {
    $r = new ReflectionFunction($fn);
    $p = $r->getParameters()[0];
    echo $fn, ' param=', $p->getName();
    echo ' type=', $p->hasType() ? (string) $p->getType() : '(none)';
    echo ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
}
$log = new ReflectionFunction('log');
echo 'log p1=', $log->getParameters()[1]->getName(), "\n";
echo 'sin=', (int) round(sin(num: M_PI / 2)), "\n";
echo 'cos=', (int) round(cos(num: 0)), "\n";
echo 'tan=', (int) round(tan(num: 0)), "\n";
echo 'asin=', (int) asin(num: 0), "\n";
echo 'acos=', (int) acos(num: 1), "\n";
echo 'atan=', (int) atan(num: 0), "\n";
echo 'exp=', (int) exp(num: 0), "\n";
echo 'log10=', (int) log10(num: 1), "\n";
echo 'log=', (int) log(num: 1), "\n";
echo 'log2=', (int) log(num: 8, base: 2), "\n";
try {
    sin(number: 0);
    echo "legacy number ok\n";
} catch (Throwable $e) {
    echo 'legacy number ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
sin param=num type=float ret=float
cos param=num type=float ret=float
tan param=num type=float ret=float
asin param=num type=float ret=float
acos param=num type=float ret=float
atan param=num type=float ret=float
exp param=num type=float ret=float
log10 param=num type=float ret=float
log param=num type=float ret=float
log p1=base
sin=1
cos=1
tan=0
asin=0
acos=0
atan=0
exp=1
log10=0
log=0
log2=3
legacy number ERR=Error: Unknown named parameter $number
