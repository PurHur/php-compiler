<?php

/**
 * #25625 — parent::/self:: string callables: is_callable / call_user_func vs $c().
 *
 * Zend resolves scope keywords for is_callable/call_user_func*, but direct $c()
 * treats them as literal class names (Error: Class "parent" not found).
 */
error_reporting(E_ALL & ~E_DEPRECATED);

class A
{
    public function f()
    {
        return 'A::f';
    }

    public static function s()
    {
        return 'A::s';
    }
}

class B extends A
{
    public function f()
    {
        return 'B::f';
    }

    public function report(): void
    {
        var_export(is_callable('parent::f'));
        echo "\n";
        try {
            var_export(call_user_func('parent::f'));
        } catch (Throwable $e) {
            echo get_class($e), ':', $e->getMessage();
        }
        echo "\n";
        $c = 'parent::f';
        try {
            var_export($c());
        } catch (Throwable $e) {
            echo get_class($e), ':', $e->getMessage();
        }
        echo "\n";
        try {
            var_export(forward_static_call('parent::s'));
        } catch (Throwable $e) {
            echo get_class($e), ':', $e->getMessage();
        }
        echo "\n";
    }
}

(new B())->report();
