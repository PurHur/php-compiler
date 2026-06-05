--TEST--
Language: #[\Override] on trait-composed method compiles (issue #5550)
--FILE--
<?php
trait T { public function f(): string { return 't'; } }
class C { use T; #[\Override] public function f(): string { return 'c'; } }
echo (new C())->f() . "\n";
?>
--EXPECT--
c
