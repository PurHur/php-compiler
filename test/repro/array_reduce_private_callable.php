<?php
/**
 * array_reduce in-scope private / out-of-scope TypeError (#25763).
 *
 * Note: use variables for the input array + callable — consecutive inline array
 * literals into array_reduce are corrupted on this VM (separate tracker).
 */
class R {
    private function add($c, $n) {
        return $c + $n;
    }

    public function run() {
        $nums = [1, 2, 3];
        $cb = [$this, 'add'];
        echo 'reduce=', array_reduce($nums, $cb, 0), "\n";
    }
}
(new R())->run();
$r = new R();
try {
    $nums = [1];
    $cb = [$r, 'add'];
    echo 'out=', array_reduce($nums, $cb, 0), "\n";
} catch (Throwable $e) {
    echo 'out=', get_class($e), ':', $e->getMessage(), "\n";
}
