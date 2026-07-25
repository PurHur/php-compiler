<?php
// Repro #23069 — final plain property must not bleed isFinal onto following hooked property.
class C {
    final public string $f = 'f';
    public string $hook {
        get => 'h';
        set { }
    }
}
echo 'f=', (new ReflectionProperty(C::class, 'f'))->isFinal() ? '1' : '0';
echo ' hook=', (new ReflectionProperty(C::class, 'hook'))->isFinal() ? '1' : '0', "\n";

class D {
    public string $hook {
        get => 'h';
        set { }
    }
    final public string $f = 'f';
}
echo 'Dhook=', (new ReflectionProperty(D::class, 'hook'))->isFinal() ? '1' : '0';
echo ' Df=', (new ReflectionProperty(D::class, 'f'))->isFinal() ? '1' : '0', "\n";
