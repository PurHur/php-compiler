--TEST--
trait use adaptations: insteadof + as alias combined (#6011)
--FILE--
<?php
trait TA {
    public function f(): string {
        return 'A';
    }
}
trait TB {
    public function f(): string {
        return 'B';
    }
}
final class C {
    use TA, TB {
        TA::f insteadof TB;
        TB::f as g;
    }
}
$c = new C();
echo $c->f(), $c->g(), "\n";
--EXPECT--
AB
