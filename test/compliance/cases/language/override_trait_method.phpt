--TEST--
Language: #[\Override] on direct trait method redefinition — compile-time fatal (#6440)
--FILE--
<?php
trait T { public function f(): void {} }
class C {
    use T;
    #[\Override]
    public function f(): void { echo "class\n"; }
}
--EXPECT_EXIT--
255
