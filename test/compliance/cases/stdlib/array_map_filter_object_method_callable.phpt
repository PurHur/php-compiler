--TEST--
array_map/array_filter in-scope private method callable (#25711, ext/standard/array.c)
--FILE--
<?php
class MapFilterInaccA {
    private function dbl($n) {
        return $n * 2;
    }

    private function odd($n) {
        return $n % 2 === 1;
    }

    public function run() {
        echo json_encode(array_map([$this, 'dbl'], [1, 2])), "\n";
        echo json_encode(array_filter([1, 2, 3], [$this, 'odd'])), "\n";
    }
}
(new MapFilterInaccA())->run();
$a = new MapFilterInaccA();
try {
    array_map([$a, 'dbl'], [1]);
    echo "map uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_filter([1], [$a, 'odd']);
    echo "filter uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
[2,4]
{"0":1,"2":3}
TypeError: array_map(): Argument #1 ($callback) must be a valid callback or null, cannot access private method MapFilterInaccA::dbl()
TypeError: array_filter(): Argument #2 ($callback) must be a valid callback or null, cannot access private method MapFilterInaccA::odd()
