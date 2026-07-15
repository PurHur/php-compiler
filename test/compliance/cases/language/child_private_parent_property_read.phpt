--TEST--
Language: child scope parent private property read — undefined + return type failure (#19005, zend_execute.c)
--FILE--
<?php
class ParentOnly {
    private string $secret = 'hidden';

    public function parentRead(): string {
        return $this->secret;
    }
}

class Child extends ParentOnly {
    public function read(): string {
        return $this->secret;
    }
}

try {
    echo (new Child())->read(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo (new Child())->parentRead(), "\n";
?>
--EXPECTF--
PHP Warning:  Undefined property: Child::$secret in %s on line %d
TypeError: Child::read(): Return value must be of type string, null returned
hidden
