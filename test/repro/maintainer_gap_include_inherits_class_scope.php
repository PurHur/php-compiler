<?php
/**
 * Maintainer gap: include() from an instance method inherits class scope (#31913).
 * Zend ZEND_INCLUDE_OR_EVAL copies called_scope; included `return self::class;` is 'C'.
 * VM/JIT previously: parseAndCompile failure — Cannot use "self" in the global scope.
 */
error_reporting(E_ALL);

class C
{
    public function f()
    {
        return include __DIR__ . '/maintainer_gap_include_inherits_class_scope_inc.php';
    }
}

class D extends C
{
    public function g()
    {
        return include __DIR__ . '/maintainer_gap_include_inherits_class_scope_static_inc.php';
    }
}

$c = new C();
echo 'self=', $c->f(), "\n";

$d = new D();
echo 'static=', $d->g(), "\n";
