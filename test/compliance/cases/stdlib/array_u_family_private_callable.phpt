--TEST--
array_udiff/uintersect/diff_uassoc in-scope private comparator (#25736)
--FILE--
<?php
class ArrayUFamilyPriv {
    private function cmp($a, $b) {
        return $a <=> $b;
    }

    public function run() {
        echo 'udiff=', json_encode(array_values(array_udiff([1, 2, 3], [2], [$this, 'cmp']))), "\n";
        echo 'uintersect=', json_encode(array_values(array_uintersect([1, 2, 3], [2, 4], [$this, 'cmp']))), "\n";
        echo 'diff_uassoc=', json_encode(array_diff_uassoc([1 => 1, 2 => 2], [2 => 2], [$this, 'cmp'])), "\n";
    }
}
(new ArrayUFamilyPriv())->run();
$u = new ArrayUFamilyPriv();
try {
    echo 'udiff_out=', json_encode(array_udiff([1], [2], [$u, 'cmp'])), "\n";
} catch (Throwable $e) {
    echo 'udiff_out=', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
udiff=[1,3]
uintersect=[2]
diff_uassoc={"1":1}
udiff_out=udiff_out=TypeError:array_udiff(): Argument #3 must be a valid callback, cannot access private method ArrayUFamilyPriv::cmp()
