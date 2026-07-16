--TEST--
Language: nullsafe ?-> on live typed property after prior null short-circuit (#19591, re-#4755)
--FILE--
<?php
class B {
    public string $x = 'x';
    public function f(): string {
        return 'ok';
    }
}
class A {
    public ?B $b = null;
}
$a = new A();
echo 'null_method=', ($a->b?->f() ?? 'n'), "\n";
$a->b = new B();
echo 'live_method=', ($a->b?->f() ?? 'n'), "\n";
echo 'live_prop=', ($a->b?->x ?? 'n'), "\n";
echo "DONE\n";
?>
--EXPECT--
null_method=n
live_method=ok
live_prop=x
DONE
