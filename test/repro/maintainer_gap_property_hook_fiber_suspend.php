<?php
class C {
    public int $x {
        get {
            Fiber::suspend('in hook');
            return 1;
        }
    }
}
$fiber = new Fiber(function (): void {
    var_export((new C())->x);
    echo "\n";
});
var_export($fiber->start());
echo "\n";
$fiber->resume();
