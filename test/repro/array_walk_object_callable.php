<?php
/**
 * array_walk / array_walk_recursive object-array callables (#25764).
 */
class W {
    private function mut(&$v, $k) {
        $v = $v * 10;
    }

    private function leaf(&$v, $k) {
        $v = $v + 1;
    }

    public function run() {
        $a = [1, 2];
        $cb = [$this, 'mut'];
        array_walk($a, $cb);
        echo 'walk=', json_encode($a), "\n";
        $a = [1, [2, 3]];
        $cb = [$this, 'leaf'];
        array_walk_recursive($a, $cb);
        echo 'rec=', json_encode($a), "\n";
    }
}
(new W())->run();
$w = new W();
try {
    $a = [1];
    $cb = [$w, 'mut'];
    array_walk($a, $cb);
    echo 'out=', json_encode($a), "\n";
} catch (Throwable $e) {
    echo 'out=', get_class($e), ':', $e->getMessage(), "\n";
}
