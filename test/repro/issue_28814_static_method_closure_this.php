<?php
/**
 * Issue #28814 — closures/arrows created in a static method that read $this must
 * throw Error like Zend (zend_closures.c); VM previously returned NULL.
 *
 * Related: #10558 (top-level only), #23704 (static closure keyword).
 */
error_reporting(E_ALL);

class A
{
    public static function arrow()
    {
        return fn () => $this;
    }

    public static function classic()
    {
        return function () {
            return $this;
        };
    }

    public function inst()
    {
        return fn () => $this;
    }
}

foreach (['arrow' => A::arrow(), 'classic' => A::classic()] as $label => $f) {
    try {
        $r = $f();
        echo "$label: OK ", var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo "$label: ", get_class($e), ':', $e->getMessage(), "\n";
    }
}

$o = new A();
try {
    $r = $o->inst()();
    echo 'inst: OK ', get_class($r), "\n";
} catch (Throwable $e) {
    echo 'inst: ', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    $r = (fn () => $this)();
    echo 'toplevel: OK ', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'toplevel: ', get_class($e), ':', $e->getMessage(), "\n";
}
