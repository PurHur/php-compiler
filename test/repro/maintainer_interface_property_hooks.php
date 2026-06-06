<?php
declare(strict_types=1);

/**
 * Issue #6620: interface property hooks — implementing class uses backing field.
 *
 * Zend reference: Zend/zend_compile.c + Zend/zend_property_hooks.c
 */
interface I {
    public int $p {
        get;
        set;
    }
}

class C implements I {
    public int $p = 1;
}

echo (new C())->p, "\n";

interface HasTitle {
    public string $title {
        get;
    }
}

class Page implements HasTitle {
    public string $title = 'home';
}

echo (new Page())->title, "\n";
