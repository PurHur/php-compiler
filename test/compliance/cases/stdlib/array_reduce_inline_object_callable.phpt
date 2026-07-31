--TEST--
array_reduce consecutive inline arrays — input + object-array callable (#25766)
--FILE--
<?php
class ArrayReduceInline {
    public function add($c, $n) {
        return $c + $n;
    }

    public function run() {
        echo 'inline=', array_reduce([1, 2], [$this, 'add'], 0), "\n";
        $nums = [1, 2];
        $cb = [$this, 'add'];
        echo 'vars=', array_reduce($nums, $cb, 0), "\n";
        echo 'noint=', array_reduce([1, 2, 3], [$this, 'add']), "\n";
    }
}
(new ArrayReduceInline())->run();
--EXPECT--
inline=3
vars=3
noint=6
