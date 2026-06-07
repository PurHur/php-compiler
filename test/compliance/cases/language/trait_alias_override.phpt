--TEST--
Language: trait method alias + #[\Override] — aliased original satisfies override check (#7384)
--FILE--
<?php
trait T { public function f(): void { echo "t\n"; } }
class C {
    use T { f as protected other; }
    #[\Override]
    public function f(): void { echo "c\n"; }
}
(new C)->f();
?>
--EXPECT--
c
