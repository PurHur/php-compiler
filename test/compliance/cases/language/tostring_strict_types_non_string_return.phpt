--TEST--
Language: untyped __toString non-string return under strict_types → TypeError (#26402, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public function __toString()
    {
        return 123;
    }
}

try {
    echo (new C), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    var_export((new C)->__toString());
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

$m = new ReflectionMethod('C', '__toString');
echo $m->hasReturnType() ? (string) $m->getReturnType() : 'none', "\n";
--EXPECT--
TypeError:C::__toString(): Return value must be of type string, int returned
TypeError:C::__toString(): Return value must be of type string, int returned
string
