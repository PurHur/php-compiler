<?php
/**
 * #[\Deprecated] on trait method — Zend names the using class (C::m), not the trait (Tr::m).
 * Issue #29392 · php-src Zend/zend_attributes.c / zend_execute.c
 */
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo 'DEP:'.$errstr."\n";

    return true;
});

trait Tr {
    #[\Deprecated('old')]
    function m()
    {
        return 1;
    }
}
class C {
    use Tr;
}
echo (new C)->m(), "\n";
