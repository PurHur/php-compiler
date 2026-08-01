--TEST--
Language: __get typed return under strict_types — Zend TypeError (zend_object_handlers.c, #26431)
--FILE--
<?php
declare(strict_types=1);
class C {
    public function __get(string $n): int {
        return "42";
    }
}
try {
    var_export((new C)->x);
    echo PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
--EXPECT--
TypeError:C::__get(): Return value must be of type int, string returned
