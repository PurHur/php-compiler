--TEST--
stdlib deg2rad/rad2deg/expm1/log1p/asinh/acosh/atanh Reflection $num + named args (#27795, basic_functions.stub.php)
--FILE--
<?php
foreach (['deg2rad', 'rad2deg', 'expm1', 'log1p', 'asinh', 'acosh', 'atanh'] as $fn) {
    $r = new ReflectionFunction($fn);
    $p = $r->getParameters()[0];
    echo $fn, ' param=', $p->getName();
    echo ' type=', $p->hasType() ? (string) $p->getType() : '(none)';
    echo ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
}
echo 'deg2rad=', (int) round(rad2deg(deg2rad(num: 180))), "\n";
echo 'rad2deg=', (int) round(rad2deg(num: M_PI)), "\n";
echo 'expm1=', (int) expm1(num: 0), "\n";
echo 'log1p=', (int) log1p(num: 0), "\n";
echo 'asinh=', (int) asinh(num: 0), "\n";
echo 'acosh=', (int) acosh(num: 1), "\n";
echo 'atanh=', (int) atanh(num: 0), "\n";
try {
    deg2rad(number: 180);
    echo "legacy number ok\n";
} catch (Throwable $e) {
    echo 'legacy number ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
deg2rad param=num type=float ret=float
rad2deg param=num type=float ret=float
expm1 param=num type=float ret=float
log1p param=num type=float ret=float
asinh param=num type=float ret=float
acosh param=num type=float ret=float
atanh param=num type=float ret=float
deg2rad=180
rad2deg=180
expm1=0
log1p=0
asinh=0
acosh=0
atanh=0
legacy number ERR=Error: Unknown named parameter $number
