--TEST--
Language: void function bare return succeeds on JIT (#4836 regression)
--FILE--
<?php
function f(): void {
    return;
}
f();
echo "ok\n";
--EXPECT--
ok
