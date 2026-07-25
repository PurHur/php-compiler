--TEST--
ReflectionProperty::isFinal() final plain before hooked sibling (#23069, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    final public string $f = "f";
    public string $hook {
        get => "h";
        set { }
    }
}
echo "f=", (new ReflectionProperty(C::class, "f"))->isFinal() ? "1" : "0";
echo " hook=", (new ReflectionProperty(C::class, "hook"))->isFinal() ? "1" : "0", "\n";

class D {
    public string $hook {
        get => "h";
        set { }
    }
    final public string $f = "f";
}
echo "Dhook=", (new ReflectionProperty(D::class, "hook"))->isFinal() ? "1" : "0";
echo " Df=", (new ReflectionProperty(D::class, "f"))->isFinal() ? "1" : "0", "\n";

class E {
    public string $hook {
        get => "h";
        set { }
    }
}
echo "Ehook=", (new ReflectionProperty(E::class, "hook"))->isFinal() ? "1" : "0", "\n";

class H {
    public string $plain = "p";
    final public string $hook {
        get => "h";
        set { }
    }
}
echo "Hplain=", (new ReflectionProperty(H::class, "plain"))->isFinal() ? "1" : "0";
echo " Hhook=", (new ReflectionProperty(H::class, "hook"))->isFinal() ? "1" : "0", "\n";
--EXPECT--
f=1 hook=0
Dhook=0 Df=1
Ehook=0
Hplain=0 Hhook=1
