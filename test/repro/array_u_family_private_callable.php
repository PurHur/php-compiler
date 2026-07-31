<?php
/**
 * array_udiff / array_uintersect / array_diff_uassoc in-scope private comparator.
 */
class U {
    private function cmp($a, $b) {
        return $a <=> $b;
    }

    public function run() {
        echo 'udiff=', json_encode(array_values(array_udiff([1, 2, 3], [2], [$this, 'cmp']))), "\n";
        echo 'uintersect=', json_encode(array_values(array_uintersect([1, 2, 3], [2, 4], [$this, 'cmp']))), "\n";
        echo 'diff_uassoc=', json_encode(array_diff_uassoc([1 => 1, 2 => 2], [2 => 2], [$this, 'cmp'])), "\n";
    }
}
(new U())->run();
$u = new U();
try {
    echo 'udiff_out=', json_encode(array_udiff([1], [2], [$u, 'cmp'])), "\n";
} catch (Throwable $e) {
    echo 'udiff_out=', get_class($e), ':', $e->getMessage(), "\n";
}
