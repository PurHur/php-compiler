--TEST--
AOT: anonymous class with method call (issue #1233)
--FILE--
<?php
$o = new class {
    public function run(): void {
        echo "ok\n";
    }
};
$o->run();
--EXPECT--
ok
--EXPECT_EXIT--
0
