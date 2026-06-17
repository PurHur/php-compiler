--TEST--
First-class callable syntax for instance methods (issue #9185)
--FILE--
<?php
class D {
    public string $x = 'ok';
    public function m(): string {
        return $this->x;
    }
}
$d = new D();
$c = $d->m(...);
var_export($c instanceof Closure);
echo "\n";
echo $c(), "\n";

--EXPECT--
true
ok

