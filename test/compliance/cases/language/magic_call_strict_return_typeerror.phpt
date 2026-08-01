--TEST--
Language: __call/__callStatic typed return under strict_types — Zend TypeError (#26427)
--FILE--
<?php
declare(strict_types=1);
class C {
    public function __call(string $n, array $a): string {
        return 5;
    }
    public static function __callStatic(string $n, array $a): string {
        return 5;
    }
}
try {
    echo (new C)->foo(), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
try {
    echo C::bar(), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
--EXPECT--
TypeError:C::__call(): Return value must be of type string, int returned
TypeError:C::__callStatic(): Return value must be of type string, int returned
