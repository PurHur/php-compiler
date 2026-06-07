--TEST--
Language: #[\Override] on direct trait method redefinition — valid (#6786)
--FILE--
<?php
trait T { public function f(): void {} }
class C {
    use T;
    #[\Override]
    public function f(): void { echo "class\n"; }
}
echo (new C())->f() . "\n";
?>
--EXPECT--
class
