--TEST--
language: Closure/WeakReference/SensitiveParameterValue excess argc → ArgumentCountError (#30867)
--FILE--
<?php
$f = function () { return 1; };
try {
    echo ($f->bindTo(null, 'static', 1))();
    echo " ACCEPTED\n";
} catch (Throwable $e) {
    echo 'bindTo ', get_class($e), ': ', $e->getMessage(), "\n";
}
$o = new stdClass();
try {
    echo WeakReference::create($o, 1)->get() === null ? "n" : "y";
    echo " ACCEPTED\n";
} catch (Throwable $e) {
    echo 'create ', get_class($e), ': ', $e->getMessage(), "\n";
}
$v = new SensitiveParameterValue('secret');
try {
    echo $v->getValue(1);
    echo " ACCEPTED\n";
} catch (Throwable $e) {
    echo 'getValue ', get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok_bind=', ($f->bindTo(null, 'static'))(), "\n";
echo 'ok_weak=', WeakReference::create($o)->get() === $o ? "y\n" : "n\n";
echo 'ok_spv=', $v->getValue(), "\n";
try {
    $f->bindTo();
    echo "bindTo0 ACCEPTED\n";
} catch (Throwable $e) {
    echo 'bindTo0 ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
bindTo ArgumentCountError: Closure::bindTo() expects at most 2 arguments, 3 given
create ArgumentCountError: WeakReference::create() expects exactly 1 argument, 2 given
getValue ArgumentCountError: SensitiveParameterValue::getValue() expects exactly 0 arguments, 1 given
ok_bind=1
ok_weak=y
ok_spv=secret
bindTo0 ArgumentCountError: Closure::bindTo() expects at least 1 argument, 0 given
