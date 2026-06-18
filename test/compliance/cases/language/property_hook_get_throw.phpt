--TEST--
Language: property get hook throw must not emit secondary TypeError (#9503, zend_property_hooks.c)
--FILE--
<?php
class C {
    public int $x {
        get {
            throw new Exception('get hook');
        }
        set {
            $this->x = $value;
        }
    }
}

$c = new C();
try {
    echo $c->x, "\n";
    echo "no-error\n";
} catch (Throwable $e) {
    echo 'caught: ', $e->getMessage(), "\n";
}
--EXPECT--
caught: get hook
