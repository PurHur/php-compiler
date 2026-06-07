--TEST--
Language: #[\Override] on class method overriding trait-composed method (#6786)
--FILE--
<?php
trait T { public function foo(): void {} }
class C { use T; #[\Override] public function foo(): void { echo "ok\n"; } }
(new C())->foo();
?>
--EXPECT--
ok
