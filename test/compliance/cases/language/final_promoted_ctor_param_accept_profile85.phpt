--TEST--
Language: final promoted ctor param accepted on PROFILE=8.5 (#31153, RFC final_promotion)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
class C { public function __construct(final public int $x) {} }
echo (new C(1))->x, "\n";
--EXPECT--
1
