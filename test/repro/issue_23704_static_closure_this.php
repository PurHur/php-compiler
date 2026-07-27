<?php

/**
 * Issue #23704 — static function closures must not bind the calling method's $this.
 *
 * Zend zend_closures.c: static closures never receive $this, even when invoked from
 * an instance method (e.g. via global).
 */
error_reporting(E_ALL);
$f = static function () {
    echo 'isset_this=';
    var_export(isset($this));
    echo "\n";
    try {
        echo 'this_class=', get_class($this), "\n";
    } catch (Throwable $e) {
        echo 'read:', get_class($e), "\n";
    }
};
class A
{
    public function t(): void
    {
        global $f;
        $f();
    }
}
(new A())->t();
