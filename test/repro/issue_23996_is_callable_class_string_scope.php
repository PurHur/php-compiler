<?php
/**
 * #23996 — is_callable([Class,"priv/prot"]) / "Class::method" inside accessible scope.
 * php-src: Zend/zend_execute_API.c — zend_is_callable_ex
 */
class A
{
    private function p() {}
    protected function q() {}
    private static function s() {}

    public function r(): void
    {
        echo 'this-priv=', var_export(is_callable([$this, 'p']), true), "\n";
        echo 'self-priv=', var_export(is_callable([self::class, 'p']), true), "\n";
        echo 'str-priv=', var_export(is_callable('A::p'), true), "\n";
        echo 'self-static-priv=', var_export(is_callable([self::class, 's']), true), "\n";
    }
}

class B extends A
{
    public function r2(): void
    {
        echo 'A-prot=', var_export(is_callable([A::class, 'q']), true), "\n";
        echo 'str-prot=', var_export(is_callable('A::q'), true), "\n";
        echo 'this-prot=', var_export(is_callable([$this, 'q']), true), "\n";
    }
}

(new A)->r();
(new B)->r2();
echo 'outside-priv=', var_export(is_callable([A::class, 'p']), true), "\n";
echo 'outside-prot=', var_export(is_callable([A::class, 'q']), true), "\n";
