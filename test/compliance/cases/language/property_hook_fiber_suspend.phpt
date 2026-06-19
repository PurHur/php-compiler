--TEST--
Language: property get hook Fiber::suspend must suspend fiber not LogicException (#9862, zend_property_hooks.c)
--FILE--
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
--EXPECT--
'in hook'
1
