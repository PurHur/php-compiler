--TEST--
mb_substr/mb_strcut runtime encoding (AOT NestedJIT #34875)
--FILE--
<?php
$e = 'UTF-' . '8';
echo mb_substr('あいう', 1, 1, $e), "\n";
echo mb_strcut('hello world', 6, 5, $e), "\n";
try {
    mb_substr('a', 0, 1, 'NOPE');
    echo "no error\n";
} catch (ValueError $err) {
    echo $err->getMessage(), "\n";
}
?>
--EXPECT--
い
world
mb_substr(): Argument #4 ($encoding) must be a valid encoding, "NOPE" given
