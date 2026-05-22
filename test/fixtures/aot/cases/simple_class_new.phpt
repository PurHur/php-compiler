--TEST--
AOT: new user class and method call
--FILE--
<?php
class C { public function run(): void { echo "ok\n"; } }
(new C())->run();
--EXPECT--
ok
--EXPECT_EXIT--
0
