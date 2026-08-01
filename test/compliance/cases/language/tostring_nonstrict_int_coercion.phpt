--TEST--
Language: untyped __toString weak coerce int; null still TypeError (#26402)
--FILE--
<?php
class C {
    public function __toString()
    {
        return 123;
    }
}
echo (new C), "\n";
echo (string) (new C), "\n";

class CNull {
    public function __toString()
    {
        return null;
    }
}
try {
    echo (new CNull), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
123
123
TypeError:CNull::__toString(): Return value must be of type string, null returned
