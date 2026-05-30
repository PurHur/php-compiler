--TEST--
inheritance variance: covariant self/child return allowed (issue #3323)
--FILE--
<?php
class Base { public function create(): self { return $this; } }
class Child extends Base { public function create(): Child { return $this; } }
echo "ok\n";
--EXPECT--
ok
