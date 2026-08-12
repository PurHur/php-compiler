--TEST--
stdlib file_put_contents() empty path — ValueError Path cannot be empty (#29294, ext/standard/file.c)
--FILE--
<?php
$expected = 'Path cannot be empty';
try {
    file_put_contents('', 'x');
    echo "FAIL: no throw\n";
} catch (ValueError $e) {
    echo $e->getMessage() === $expected ? "empty:{$expected}\n" : "empty:{$e->getMessage()}\n";
}
$empty = substr('x', 1);
try {
    file_put_contents($empty, 'x');
    echo "FAIL: non-literal no throw\n";
} catch (ValueError $e) {
    echo $e->getMessage() === $expected ? "nonlit:{$expected}\n" : "nonlit:{$e->getMessage()}\n";
}
echo "ok\n";
--EXPECT--
empty:Path cannot be empty
nonlit:Path cannot be empty
ok
