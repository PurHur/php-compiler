--TEST--
stdlib mb_* invalid encoding — ValueError not LogicException (ext/mbstring, #27945 / re-#13377)
--FILE--
<?php
foreach ([
    fn() => mb_substr('a', 0, 1, 'nope'),
    fn() => mb_strpos('a', 'a', 0, 'nope'),
    fn() => mb_str_split('a', 1, 'nope'),
] as $i => $fn) {
    try {
        $fn();
        echo "$i ok\n";
    } catch (Throwable $e) {
        echo $i, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo mb_substr('hello', 0, 2, 'utf8'), "\n";
--EXPECT--
0 ValueError: mb_substr(): Argument #4 ($encoding) must be a valid encoding, "nope" given
1 ValueError: mb_strpos(): Argument #4 ($encoding) must be a valid encoding, "nope" given
2 ValueError: mb_str_split(): Argument #3 ($encoding) must be a valid encoding, "nope" given
he
