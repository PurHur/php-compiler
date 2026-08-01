--TEST--
Fiber::getCurrent() comparison as var_export()/print_r() arg (issue #26703)
--FILE--
<?php
$f = new Fiber(function () {
    echo var_export(Fiber::getCurrent() !== null, true), "\n";
    echo print_r(Fiber::getCurrent() !== null, true), "\n";
    $c = Fiber::getCurrent() !== null;
    echo var_export($c, true), "\n";
});
$f->start();
--EXPECT--
true
1
true
