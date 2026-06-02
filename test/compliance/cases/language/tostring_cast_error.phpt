--TEST--
Language: (string) cast __toString — Error + coercion parity (zend_object_handlers.c, #4495)
--FILE--
<?php
class NoToString {
}

class CoerceReturn {
    public function __toString() {
        return 1;
    }
}

try {
    (string) new NoToString();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

echo (string) new CoerceReturn(), "\n";
--EXPECT--
Object of class NoToString could not be converted to string
1
