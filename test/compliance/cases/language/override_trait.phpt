--TEST--
Language: #[\Override] on trait-composed method compiles (issue #5550)
--FILE--
<?php
trait T { public function f(): void {} }
class C { use T; #[\Override] public function f(): void {} }
echo "ok\n";
?>
--EXPECT--
ok
