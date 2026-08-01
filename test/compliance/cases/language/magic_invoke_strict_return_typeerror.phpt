--TEST--
Language: __invoke typed return under strict_types — Zend TypeError (#26426)
--FILE--
<?php
declare(strict_types=1);
class C {
    public function __invoke(): string {
        return 5;
    }
}
try {
    echo (new C)(), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
$o = new C;
try {
    echo $o(), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
try {
    echo $o->__invoke(), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
--EXPECT--
TypeError:C::__invoke(): Return value must be of type string, int returned
TypeError:C::__invoke(): Return value must be of type string, int returned
TypeError:C::__invoke(): Return value must be of type string, int returned
