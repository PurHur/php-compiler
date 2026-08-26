<?php
// #35094 — packed [$obj,'method'] array callable must match Zend under AOT (peer #4040 / #35090).
class C {
    public function f($x) {
        return $x * 3;
    }
}
$o = new C;
echo [$o, 'f'](2), "\n";
$c = [$o, 'f'];
echo $c(2), "\n";
echo call_user_func([$o, 'f'], 2), "\n";
