--TEST--
Language: __isset typed return under strict_types — Zend TypeError (zend_object_handlers.c, #26428)
--FILE--
<?php
declare(strict_types=1);
class C {
    public function __isset(string $n): bool {
        return 1;
    }
}
try {
    var_export(isset((new C)->foo));
    echo PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
--EXPECT--
TypeError:C::__isset(): Return value must be of type bool, int returned
