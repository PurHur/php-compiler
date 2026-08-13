<?php

/**
 * #30648 — get_called_class() excess argc → ArgumentCountError (Zend/zend_builtin_functions.c).
 */
class A
{
    public static function f(): void
    {
        try {
            get_called_class(1);
            echo "NO_THROW\n";
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
        try {
            get_called_class(1, 2);
            echo "NO_THROW2\n";
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
        echo 'ok=', get_called_class(), "\n";
    }
}
A::f();
