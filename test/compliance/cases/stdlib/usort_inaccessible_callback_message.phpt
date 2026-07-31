--TEST--
usort inaccessible private/protected callback TypeError (#25712)
--FILE--
<?php
class UsortInaccA {
    private function cmp($a, $b) {
        return $a <=> $b;
    }

    protected function cmpProt($a, $b) {
        return $a <=> $b;
    }

    public function sortInScope() {
        $arr = [3, 1, 2];
        usort($arr, [$this, 'cmp']);

        return $arr;
    }
}
class UsortInaccB extends UsortInaccA {
    public function sortProtected() {
        $arr = [3, 1, 2];
        usort($arr, [$this, 'cmpProt']);

        return $arr;
    }
}
$a = new UsortInaccA();
echo implode(',', $a->sortInScope()), "\n";
try {
    $arr = [3, 1, 2];
    usort($arr, [$a, 'cmp']);
    echo "private uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $arr = [3, 1, 2];
    usort($arr, [$a, 'cmpProt']);
    echo "protected uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo implode(',', (new UsortInaccB())->sortProtected()), "\n";
--EXPECT--
1,2,3
TypeError: usort(): Argument #2 ($callback) must be a valid callback, cannot access private method UsortInaccA::cmp()
TypeError: usort(): Argument #2 ($callback) must be a valid callback, cannot access protected method UsortInaccA::cmpProt()
1,2,3
