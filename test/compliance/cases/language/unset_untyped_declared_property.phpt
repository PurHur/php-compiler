--TEST--
Language: unset() on untyped declared property — Warning + NULL not typed Error (#22021, zend_object_handlers.c)
--FILE--
<?php
error_reporting(E_ALL);
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

class C {
    public $a = 1;
}
$c = new C();
unset($c->a);
echo 'isset=', var_export(isset($c->a), true), "\n";
echo 'prop_exists=', var_export(property_exists($c, 'a'), true), "\n";
try {
    $val = $c->a;
    echo 'read=', var_export($val, true), "\n";
} catch (Throwable $e) {
    echo 'throw=', get_class($e), ':', $e->getMessage(), "\n";
}

class T {
    public int $i = 0;
}
$t = new T();
unset($t->i);
try {
    $val = $t->i;
    echo 'typed=', var_export($val, true), "\n";
} catch (Throwable $e) {
    echo 'typed_throw=', get_class($e), ':', $e->getMessage(), "\n";
}

class M {
    public mixed $m = 1;
}
$m = new M();
unset($m->m);
try {
    $val = $m->m;
    echo 'mixed=', var_export($val, true), "\n";
} catch (Throwable $e) {
    echo 'mixed_throw=', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
isset=false
prop_exists=true
W:Undefined property: C::$a
read=NULL
typed_throw=Error:Typed property T::$i must not be accessed before initialization
mixed_throw=Error:Typed property M::$m must not be accessed before initialization
