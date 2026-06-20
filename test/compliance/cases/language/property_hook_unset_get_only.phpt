--TEST--
Language: unset() on get-only property hook — Cannot unset hooked property (#9800, zend_property_hooks.c)
--FILE--
<?php
declare(strict_types=1);

class Box {
    public string $label {
        get => strtoupper($this->label);
    }
}

try {
    unset((new Box())->label);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot unset hooked property Box::$label
