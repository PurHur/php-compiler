--TEST--
Language: #[\Override] on trait-composed method — compile-time fatal (#6440)
--FILE--
<?php
trait T { public function f(): string { return 't'; } }
class C { use T; #[\Override] public function f(): string { return 'c'; } }
echo (new C())->f() . "\n";
?>
--EXPECT_EXIT--
255
