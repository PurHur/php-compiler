<?php
/**
 * #25711 — array_map/array_filter in-scope [$this, privateMethod] vs out-of-scope TypeError.
 */
class A {
    private function dbl($n) {
        return $n * 2;
    }

    private function odd($n) {
        return $n % 2 === 1;
    }

    public function run() {
        echo 'is_callable=', var_export(is_callable([$this, 'dbl']), true), "\n";
        try {
            echo 'map=', json_encode(array_map([$this, 'dbl'], [1, 2])), "\n";
        } catch (Throwable $e) {
            echo 'map=', get_class($e), ':', $e->getMessage(), "\n";
        }
        try {
            echo 'filter=', json_encode(array_filter([1, 2, 3], [$this, 'odd'])), "\n";
        } catch (Throwable $e) {
            echo 'filter=', get_class($e), ':', $e->getMessage(), "\n";
        }
    }
}

$a = new A();
$a->run();
try {
    echo 'map_out=', json_encode(array_map([$a, 'dbl'], [1])), "\n";
} catch (Throwable $e) {
    echo 'map_out=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo 'filter_out=', json_encode(array_filter([1], [$a, 'odd'])), "\n";
} catch (Throwable $e) {
    echo 'filter_out=', get_class($e), ':', $e->getMessage(), "\n";
}
