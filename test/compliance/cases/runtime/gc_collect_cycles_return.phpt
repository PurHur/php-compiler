--TEST--
runtime gc_collect_cycles() return — refcount dtor not counted (#10111)
--FILE--
<?php
class C {
    public function __destruct() {
        echo "dtor\n";
    }
}
$a = new C();
unset($a);
echo gc_collect_cycles(), "\n";
--EXPECT--
dtor
0
