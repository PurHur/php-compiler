--TEST--
array_reduce in-scope private callable (#25763)
--FILE--
<?php
class ArrayReducePriv {
    private function add($c, $n) {
        return $c + $n;
    }

    public function run() {
        $nums = [1, 2, 3];
        $cb = [$this, 'add'];
        echo 'reduce=', array_reduce($nums, $cb, 0), "\n";
    }
}
(new ArrayReducePriv())->run();
$r = new ArrayReducePriv();
try {
    $nums = [1];
    $cb = [$r, 'add'];
    echo 'out=', array_reduce($nums, $cb, 0), "\n";
} catch (Throwable $e) {
    echo 'out=', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
reduce=6
out=out=TypeError:array_reduce(): Argument #2 ($callback) must be a valid callback, cannot access private method ArrayReducePriv::add()
