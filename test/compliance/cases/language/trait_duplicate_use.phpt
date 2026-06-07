--TEST--
Duplicate trait in use list dedupes silently (#5400)
--FILE--
<?php
trait T { public function foo(): int { return 1; } }
class C { use T, T; }
echo (new C())->foo(), "\n";
?>
--EXPECT--
1
